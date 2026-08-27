<?php

namespace App\Http\Controllers\Admin\Campaign;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Services\AdminLogger;
use App\Services\Campaign\CampaignRenderer;
use App\Support\CampaignAudience;
use App\Support\EventTemplates;
use App\Support\MailSettings;
use App\Support\SmsSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Campaigns: reaching people about something they did not ask for.
 *
 * The distinction from the event notifications matters throughout. Those are
 * transactional and go to whoever is on an entry. These are marketing, they only
 * go to people who agreed, and every one of them carries a way out.
 */
class CampaignController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * How many names the picker will list at once.
     *
     * Generous, because the operator has to be able to tick everybody, and a
     * partial list makes "select all" a lie. Capped all the same: a few thousand
     * rows of JSON and checkboxes stops being a usable screen.
     */
    private const PICKER_LIMIT = 1000;

    /**
     * Up to this many messages go out during the request that asked for them.
     *
     * Chosen so the whole batch finishes well inside a normal execution limit even
     * when the mail server is slow to answer. Beyond it the queue takes over.
     */
    private const INLINE_LIMIT = 25;

    /**
     * The two channels, which double as the tabs on the list.
     */
    private const CHANNELS = [
        EventTemplates::CHANNEL_EMAIL => 'Email Campaign',
        EventTemplates::CHANNEL_SMS => 'SMS Campaign',
    ];

    /**
     * What each tab holds, shown above its table.
     *
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        EventTemplates::CHANNEL_EMAIL => [
            'label' => 'Email Campaign',
            'icon' => 'mail',
            'title' => 'Email Campaigns',
            'description' => 'Longer messages with tracked links, sent to people who agreed to hear from you.',
            'accent' => 'blue',
        ],
        EventTemplates::CHANNEL_SMS => [
            'label' => 'SMS Campaign',
            'icon' => 'mobile',
            'title' => 'SMS Campaigns',
            'description' => 'Short messages billed per segment. No open or click tracking; a text has nowhere to hide an image.',
            'accent' => 'amber',
        ],
    ];

    public function index(Request $request)
    {
        $channel = $this->resolveChannel($request->query('tab'));
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $query = Campaign::query()
            ->with(['audienceEvent', 'creator'])
            ->where('channel', $channel)
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status));

        $counts = [
            EventTemplates::CHANNEL_EMAIL => Campaign::where('channel', EventTemplates::CHANNEL_EMAIL)->count(),
            EventTemplates::CHANNEL_SMS => Campaign::where('channel', EventTemplates::CHANNEL_SMS)->count(),
        ];

        return view('admin.campaign.index', [
            // Shaped the way settings-shell wants its tabs: label, icon and a count.
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $tab, string $slug) => [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'count' => $counts[$slug] ?? 0,
                ])
                ->all(),

            'activeTab' => $channel,
            'intro' => self::TAB_INTRO[$channel],
            'campaigns' => $query->latest()->paginate(self::PER_PAGE)->withQueryString(),
            'statuses' => Campaign::STATUSES,
            'filters' => compact('search', 'status'),
            'isFiltered' => $search !== '' || $status !== '',

            // Across both channels, so the pair does not appear to contradict
            // itself as tabs are switched.
            'totals' => [
                'sent' => (int) Campaign::sum('sent_count'),
                'unsubscribed' => (int) Campaign::sum('unsubscribed_count'),
            ],

            // Creating one, not editing an existing one: this flag only decides
            // whether the New Campaign button appears on the list.
            'canUpdate' => $request->user()->hasPermission('campaigns.create'),
            'delivery' => $this->deliveryState($channel),
        ]);
    }

    public function create(Request $request)
    {
        $channel = $this->resolveChannel($request->query('channel'));

        return view('admin.campaign.form', $this->formData($request, new Campaign([
            'channel' => $channel,
            'audience_type' => CampaignAudience::ALL,
        ])));
    }

    /**
     * The people a segment covers, so the form can list them to be ticked.
     *
     * Answers with everybody addressable on the channel, not only those who agreed,
     * because the point of the list is to choose. Whether each one agreed is
     * returned alongside so the screen can say so, and the suppression list is
     * applied here rather than shown greyed out: somebody who asked to be left
     * alone should not appear as a thing that could be ticked at all.
     */
    public function recipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'audience_type' => ['required', Rule::in(array_keys(CampaignAudience::SEGMENTS))],
            'audience_event_id' => ['nullable', 'integer'],
        ]);

        $channel = $data['channel'];
        $isEmail = $channel === EventTemplates::CHANNEL_EMAIL;
        $eventId = $data['audience_type'] === CampaignAudience::EVENT
            ? ($data['audience_event_id'] ?? null)
            : null;

        // The one segment that cannot answer without an event chosen. Returned as an
        // empty list with a reason rather than as an error, because "you have not
        // picked an event yet" is a normal state of a form, not a fault.
        if ($data['audience_type'] === CampaignAudience::EVENT && $eventId === null) {
            return response()->json([
                'contacts' => [],
                'total' => 0,
                'truncated' => false,
                'note' => 'Choose an event to see who is on it.',
            ]);
        }

        $query = CampaignAudience::candidates($data['audience_type'], $eventId, $channel)
            ->with('firstEvent:id,title');

        $total = $query->count();

        $contacts = $query
            ->orderByRaw('COALESCE(NULLIF(name, ""), email, phone)')
            ->limit(self::PICKER_LIMIT)
            ->get();

        return response()->json([
            'contacts' => $contacts->map(fn (CampaignContact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name ?: '—',
                'address' => $isEmail ? $contact->email : $contact->phone,
                'consented' => $isEmail ? (bool) $contact->consent_email : (bool) $contact->consent_sms,
                'event' => $contact->firstEvent?->title,
            ])->all(),

            'total' => $total,
            'truncated' => $total > self::PICKER_LIMIT,
            'note' => $total > self::PICKER_LIMIT
                ? sprintf('Showing the first %d of %d. Narrow the audience to see the rest.', self::PICKER_LIMIT, $total)
                : null,
        ]);
    }

    public function edit(Request $request, Campaign $campaign)
    {
        if (! $campaign->isEditable()) {
            return redirect()
                ->route('admin.campaigns.show', $campaign)
                ->withErrors(['campaign' => 'A campaign that has been sent cannot be edited. Duplicate it instead.']);
        }

        return view('admin.campaign.form', $this->formData($request, $campaign));
    }

    public function store(Request $request, CampaignRenderer $renderer)
    {
        $data = $this->validated($request);

        /*
         | Send Now sends. It does not save a draft and leave the operator to find a
         | button on another screen.
         |
         | Everything that could stop the send is settled before the campaign row
         | exists, so a refusal returns to this form with the wording and the ticks
         | intact instead of leaving a half made draft behind to be tidied up.
         */
        if ($this->wantsToSend($request)) {
            if (! $this->maySend($request)) {
                return back()->withInput()->withErrors([
                    'campaign' => 'Your account may write campaigns but not send them. '
                        . 'Save it as a draft for somebody who can.',
                ]);
            }

            $contacts = CampaignAudience::picked($data['audience_contact_ids'] ?? [], $data['channel'])->get();
            $blocker = $this->blocker($data['channel'], $contacts->count());

            if ($blocker !== null) {
                return back()->withInput()->withErrors(['campaign' => $blocker]);
            }

            return $this->dispatchTo($this->persist($request, $data), $contacts, $renderer);
        }

        $campaign = $this->persist($request, $data);

        return redirect()
            ->route('admin.campaigns.show', $campaign)
            ->with('status', 'Saved as a draft. Nothing has been sent.');
    }

    public function update(Request $request, Campaign $campaign, CampaignRenderer $renderer)
    {
        if (! $campaign->isEditable()) {
            return back()->withErrors(['campaign' => 'A campaign that has been sent cannot be edited.']);
        }

        $data = $this->validated($request);

        if ($this->wantsToSend($request)) {
            if (! $this->maySend($request)) {
                return back()->withInput()->withErrors([
                    'campaign' => 'Your account may edit campaigns but not send them. '
                        . 'Save the changes and let somebody who can send it.',
                ]);
            }

            $contacts = CampaignAudience::picked($data['audience_contact_ids'] ?? [], $data['channel'])->get();
            $blocker = $this->blocker($data['channel'], $contacts->count());

            if ($blocker !== null) {
                return back()->withInput()->withErrors(['campaign' => $blocker]);
            }

            $campaign->update($data);
            AdminLogger::activity('campaigns.update', sprintf('Updated campaign %s.', $campaign->name));

            return $this->dispatchTo($campaign, $contacts, $renderer);
        }

        $campaign->update($data);

        AdminLogger::activity('campaigns.update', sprintf('Updated campaign %s.', $campaign->name));

        return redirect()
            ->route('admin.campaigns.show', $campaign)
            ->with('status', 'Saved. Still a draft.');
    }

    /**
     * Write a new campaign as a draft.
     *
     * A campaign is born a draft even when it is about to be sent one line later,
     * so that a failure between the two leaves a row that says truthfully that
     * nothing has gone out.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(Request $request, array $data): Campaign
    {
        $campaign = Campaign::create($data + [
            'status' => Campaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        AdminLogger::activity('campaigns.create', sprintf('Created campaign %s.', $campaign->name));

        return $campaign;
    }

    /**
     * One campaign: what it says, who it reaches, and what happened.
     */
    public function show(Request $request, Campaign $campaign, CampaignRenderer $renderer)
    {
        $campaign->load(['audienceEvent', 'creator', 'template']);

        /*
         | Who a draft would still go to.
         |
         | Read back from the contacts rather than trusting the stored list, because
         | somebody on it may have unsubscribed since the campaign was written, and
         | the screen must show the list as it is now rather than as it was chosen.
         */
        $pendingContacts = $campaign->isDraft()
            ? ($campaign->hasPickedRecipients()
                ? CampaignAudience::picked($campaign->audience_contact_ids, $campaign->channel)
                : CampaignAudience::query($campaign->audience_type, $campaign->audience_event_id, $campaign->channel))
                ->orderByRaw('COALESCE(NULLIF(name, ""), email, phone)')
                ->limit(self::PICKER_LIMIT)
                ->get()
            : collect();

        return view('admin.campaign.show', [
            'campaign' => $campaign,
            'pending' => $pendingContacts->count(),
            'pendingContacts' => $pendingContacts,
            'preview' => $renderer->renderSample($campaign->body),
            'previewSubject' => $renderer->renderSample((string) $campaign->subject),
            'smsSegments' => $campaign->isEmail() ? null : $this->smsSegments($campaign->body),
            'delivery' => $this->deliveryState($campaign->channel),

            // What stops the send, or null. The screen shows the button either way: a
            // control that disappears leaves the operator hunting for something that
            // was never there.
            'blocker' => $campaign->isDraft()
                ? $this->blocker($campaign->channel, $pendingContacts->count())
                : null,

            'canSend' => $request->user()->hasPermission('campaigns.send'),
            'canUpdate' => $request->user()->hasPermission('campaigns.update'),

            // Its own permission now. Deleting a draft is not the same act as
            // editing one, and the button is the only way to do it.
            'canDelete' => $request->user()->hasPermission('campaigns.delete'),

            'recipients' => $campaign->isDraft() ? null : $campaign->recipients()
                ->with('contact')
                ->latest('id')
                ->paginate(self::PER_PAGE),
            'links' => $campaign->links()->withCount('clicks')->orderByDesc('click_count')->get(),
        ]);
    }

    /**
     * Build the recipient list and hand every message to the queue.
     *
     * The list is written before anything is queued, so a campaign interrupted
     * halfway can be seen for what it is rather than leaving no trace. Each
     * recipient's suppression is checked again inside the job, because somebody can
     * unsubscribe while the queue is still draining.
     */
    public function send(Request $request, Campaign $campaign, CampaignRenderer $renderer)
    {
        if (! $campaign->isDraft()) {
            return redirect()
                ->route('admin.campaigns.show', $campaign)
                ->withErrors(['campaign' => 'This campaign has already been sent.']);
        }

        // The people chosen when it was saved. Falling back to the segment for
        // campaigns written before the picker existed.
        $contacts = $campaign->hasPickedRecipients()
            ? CampaignAudience::picked($campaign->audience_contact_ids, $campaign->channel)->get()
            : CampaignAudience::query($campaign->audience_type, $campaign->audience_event_id, $campaign->channel)->get();

        $blocker = $this->blocker($campaign->channel, $contacts->count());

        if ($blocker !== null) {
            return redirect()
                ->route('admin.campaigns.show', $campaign)
                ->withErrors(['campaign' => $blocker]);
        }

        return $this->dispatchTo($campaign, $contacts, $renderer);
    }

    /**
     * Write the recipient list and hand every message to the queue.
     *
     * The list is written before anything is queued, so a campaign interrupted
     * halfway can be seen for what it is rather than leaving no trace. Each
     * recipient's suppression is checked again inside the job, because somebody can
     * unsubscribe while the queue is still draining.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\CampaignContact>  $contacts
     */
    private function dispatchTo(Campaign $campaign, $contacts, CampaignRenderer $renderer)
    {
        $addressColumn = $campaign->isEmail() ? 'email' : 'phone';

        DB::transaction(function () use ($campaign, $contacts, $addressColumn, $renderer) {
            // Recorded before the first message goes out, so the click tracker has
            // rows to resolve against from the very first press.
            $renderer->captureLinks($campaign);

            foreach ($contacts as $contact) {
                $campaign->recipients()->create([
                    'campaign_contact_id' => $contact->id,
                    'address' => $contact->{$addressColumn},
                    'status' => CampaignRecipient::STATUS_QUEUED,
                ]);
            }

            $campaign->update([
                'status' => Campaign::STATUS_SENDING,
                'recipients_total' => $contacts->count(),
                'started_at' => now(),
                'finished_at' => null,
            ]);
        });

        // Dispatched after the commit, so a job cannot pick up a recipient row the
        // transaction went on to roll back.
        $campaign->load('links');

        $ids = $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_QUEUED)
            ->where('is_test', false)
            ->pluck('id');

        /*
         | A small batch leaves during this request. A large one goes to the queue.
         |
         | Send Now has to mean sent. On a queue connection with no worker running,
         | dispatching would mean nothing happened at all, which is indistinguishable
         | from a broken button.
         |
         | Inline is only safe while the batch is small: a few hundred SMTP handshakes
         | in one web request would meet the execution limit halfway through, and a
         | send that stopped in the middle is worse than one that waited. The job
         | records each outcome on its own row either way, so a single bad address
         | cannot take the rest down with it.
         */
        $inline = $ids->count() <= self::INLINE_LIMIT;

        foreach ($ids as $id) {
            $inline
                ? SendCampaignMessage::dispatchSync($id)
                : SendCampaignMessage::dispatch($id);
        }

        $count = $contacts->count();
        $noun = Str::plural($campaign->isEmail() ? 'email' : 'text message', $count);

        AdminLogger::activity(
            'campaigns.send',
            sprintf('Sent campaign %s to %d recipients over %s.', $campaign->name, $count, $campaign->channel),
        );

        AdminLogger::audit($campaign, 'campaign.sent', null, [
            'name' => $campaign->name,
            'channel' => $campaign->channel,
            'audience' => $campaign->audienceLabel(),
            'recipients' => $count,
        ]);

        return redirect()
            ->route('admin.campaigns.show', $campaign)
            ->with('status', $inline
                ? sprintf('%d %s sent. The table below says what happened to each one.', $count, $noun)
                : sprintf('%d %s queued, and leaving as the worker gets to them.', $count, $noun));
    }

    /**
     * Send one message to the operator, before committing to the whole list.
     */
    public function test(Request $request, Campaign $campaign, CampaignRenderer $renderer)
    {
        $rules = $campaign->isEmail()
            ? ['test_to' => ['required', 'email:rfc', 'max:190']]
            : ['test_to' => ['required', 'string', 'max:30']];

        $data = $request->validate($rules);

        // A throwaway contact and recipient, so the real tracking figures are not
        // polluted by the operator opening their own test.
        $contact = CampaignContact::firstOrCreate(
            $campaign->isEmail() ? ['email' => mb_strtolower($data['test_to'])] : ['phone' => \App\Support\PhoneNumber::toInternational($data['test_to'])],
            ['name' => $request->user()->name, 'consent_source' => CampaignContact::SOURCE_ADMIN],
        );

        $renderer->captureLinks($campaign);
        $campaign->load('links');

        $recipient = $campaign->recipients()->create([
            'campaign_contact_id' => $contact->id,
            'address' => $data['test_to'],
            // Flagged, so it stays out of the totals and cannot push a draft into
            // the sent state. Checking your own wording must not finish the campaign.
            'is_test' => true,
            'status' => CampaignRecipient::STATUS_QUEUED,
            'reason' => 'Test send, not part of the audience.',
        ]);

        // Sent during this request rather than queued. One message is well inside
        // any execution limit, and a test that arrives tomorrow when a worker
        // happens to run tells the operator nothing about their wording today.
        SendCampaignMessage::dispatchSync($recipient->id);

        $recipient->refresh();

        return back()->with('status', $recipient->status === CampaignRecipient::STATUS_SENT
            ? sprintf('Test sent to %s.', $data['test_to'])
            : sprintf('The test to %s did not go: %s', $data['test_to'], $recipient->reason ?: 'no reason recorded.'));
    }

    public function destroy(Campaign $campaign)
    {
        if (! $campaign->isDraft()) {
            return back()->withErrors([
                'campaign' => 'A campaign that has been sent is a record of what people received and cannot be deleted.',
            ]);
        }

        $name = $campaign->name;
        $campaign->delete();

        AdminLogger::activity('campaigns.delete', sprintf('Deleted draft campaign %s.', $name));

        return redirect()
            ->route('admin.campaigns.index')
            ->with('status', sprintf('Draft %s deleted.', $name));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Which of the two buttons on the form was pressed.
     *
     * Named rather than assumed, because the form has one action and two intents:
     * writing a campaign and sending it are usually one job, but not always.
     */
    private function wantsToSend(Request $request): bool
    {
        return $request->input('intent') === 'send';
    }

    /**
     * Whether this account may actually send, on top of wanting to.
     *
     * Checked in the controller rather than on the route, because the route has to
     * admit somebody who may write a campaign but not send one. Without this the
     * Send Now button would be a way round campaigns.send entirely: the form posts
     * to store, store is gated on campaigns.create, and the send would follow.
     */
    private function maySend(Request $request): bool
    {
        return $request->user()->hasPermission('campaigns.send');
    }

    /**
     * What stops this send, or null when nothing does.
     *
     * Only two things can, now that the operator names the recipients: the channel
     * is not configured, or none of the people named can actually be sent to. Both
     * are stated as the problem they are, with the fix implied.
     */
    private function blocker(string $channel, int $count): ?string
    {
        $state = $this->deliveryState($channel);

        if (! $state['ready']) {
            return $state['summary'];
        }

        if ($count === 0) {
            return 'Nobody was chosen, or the people chosen have since unsubscribed. '
                . 'Tick at least one name in the list.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'campaign_template_id' => ['nullable', 'exists:campaign_templates,id'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'audience_type' => ['required', Rule::in(array_keys(CampaignAudience::SEGMENTS))],
            'audience_event_id' => ['nullable', 'exists:events,id'],

            // Required to send, optional to save: a draft is allowed to be an
            // unfinished thought, but nothing goes out to nobody.
            'contact_ids' => [$this->wantsToSend($request) ? 'required' : 'nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:campaign_contacts,id'],
        ], [
            'body.required' => 'Write the message before saving.',
            'name.required' => 'Give the campaign a name so you can find it again.',
            'contact_ids.required' => 'Tick at least one person in the list before sending.',
        ]);

        // A subject is only meaningful on email, and an event is only meaningful
        // for the one-event segment. Cleared rather than left over from a change of
        // mind, so the record cannot describe a shape it is not.
        if ($data['channel'] !== EventTemplates::CHANNEL_EMAIL) {
            $data['subject'] = null;
        }

        if ($data['audience_type'] !== CampaignAudience::EVENT) {
            $data['audience_event_id'] = null;
        }

        /*
         | The ticked names move to the column that stores them, as integers, so
         | nothing downstream has to wonder whether it is holding strings off a form.
         | Null rather than an empty array when nothing was ticked, so "addressed by
         | rule" and "nobody chosen" are not the same value in the database.
         */
        $ids = collect($data['contact_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        unset($data['contact_ids']);

        $data['audience_contact_ids'] = $ids === [] ? null : $ids;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, Campaign $campaign): array
    {
        return [
            'campaign' => $campaign,
            'channels' => self::CHANNELS,
            'segments' => CampaignAudience::SEGMENTS,
            'events' => CampaignAudience::eventOptions(),
            'templates' => CampaignTemplate::where('channel', $campaign->channel ?: EventTemplates::CHANNEL_EMAIL)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'placeholders' => CampaignRenderer::PLACEHOLDERS,
            'delivery' => $this->deliveryState($campaign->channel ?: EventTemplates::CHANNEL_EMAIL),
            'canSend' => $request->user()->hasPermission('campaigns.send'),

            /*
             | Who is ticked when the page opens.
             |
             | old() first, so a rejected submission comes back with the choice intact
             | rather than making somebody tick fourteen boxes again over a missing
             | subject line. Otherwise whatever was saved on the draft.
             */
            'picked' => collect(old('contact_ids', $campaign->audience_contact_ids ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function resolveChannel(?string $value): string
    {
        return array_key_exists((string) $value, self::CHANNELS)
            ? (string) $value
            : EventTemplates::CHANNEL_EMAIL;
    }

    /**
     * Whether this channel could actually deliver, said plainly.
     *
     * @return array{ready: bool, summary: string, settingsRoute: string}
     */
    private function deliveryState(string $channel): array
    {
        if ($channel === EventTemplates::CHANNEL_SMS) {
            return [
                'ready' => SmsSettings::canSend(),
                'summary' => SmsSettings::canSend()
                    ? sprintf('%s is ready. Each recipient costs one or more message segments.', SmsSettings::providerLabel())
                    : 'SMS cannot be sent yet. ' . SmsSettings::summary(),
                'settingsRoute' => route('admin.settings.integration', ['tab' => 'sms']),
            ];
        }

        MailSettings::apply();
        $profile = MailSettings::effective();
        $ready = filled($profile['Host']) || $profile['Mailer'] === 'log';

        return [
            'ready' => $ready,
            'summary' => $ready
                ? sprintf('Mail goes out through %s as %s.', $profile['Mailer'], $profile['From Address'])
                : 'No mail transport is configured, so nothing can be sent.',
            'settingsRoute' => route('admin.settings.integration', ['tab' => 'email']),
        ];
    }

    /**
     * How many SMS segments a body costs.
     *
     * Shown because the bill is per segment, not per recipient: a body that runs
     * three characters over 160 doubles the cost of the whole campaign.
     */
    private function smsSegments(string $body): int
    {
        $length = mb_strlen($body);

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
