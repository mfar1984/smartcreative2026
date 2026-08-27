<?php

namespace App\Http\Controllers\Admin\Campaign;

use App\Http\Controllers\Controller;
use App\Models\CampaignContact;
use App\Services\AdminLogger;
use App\Support\CampaignAudience;
use App\Support\EventTemplates;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who is on the list, and why most of them cannot be sent to.
 *
 * The second half is the point. A screen that showed only reachable people would
 * make an operator think the list was tiny; showing the whole list with the reason
 * each person is held back turns "why is this only 12" into an answerable question.
 */
class AudienceController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request)
    {
        $channel = $request->query('channel') === EventTemplates::CHANNEL_SMS
            ? EventTemplates::CHANNEL_SMS
            : EventTemplates::CHANNEL_EMAIL;

        $search = trim((string) $request->query('q', ''));
        $state = (string) $request->query('state', '');

        $contacts = CampaignContact::query()
            ->with('firstEvent')
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($state === 'reachable', fn (Builder $q) => $q->reachable($channel))
            ->when($state === 'suppressed', fn (Builder $q) => $q->suppressed())
            ->when($state === 'no_consent', fn (Builder $q) => $q
                ->where($channel === EventTemplates::CHANNEL_SMS ? 'consent_sms' : 'consent_email', false)
                ->whereNull('unsubscribed_at'))
            ->orderByDesc('last_seen_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Every segment with its real figures, so the decision of which to send to
        // is made against numbers rather than guesses.
        $segments = [];

        foreach (CampaignAudience::SEGMENTS as $key => $definition) {
            if ($key === CampaignAudience::EVENT) {
                continue;
            }

            $segments[$key] = $definition + CampaignAudience::summarise($key, null, $channel);
        }

        $events = [];

        foreach (CampaignAudience::eventOptions() as $id => $title) {
            $events[$id] = ['title' => $title]
                + CampaignAudience::summarise(CampaignAudience::EVENT, (int) $id, $channel);
        }

        return view('admin.campaign.audiences', [
            'channel' => $channel,
            'contacts' => $contacts,
            'segments' => $segments,
            'events' => $events,
            'filters' => compact('search', 'state'),
            'totals' => [
                'contacts' => CampaignContact::count(),
                'reachable_email' => CampaignContact::query()->reachable(EventTemplates::CHANNEL_EMAIL)->count(),
                'reachable_sms' => CampaignContact::query()->reachable(EventTemplates::CHANNEL_SMS)->count(),
                'suppressed' => CampaignContact::query()->suppressed()->count(),
            ],
            'canExport' => $request->user()->hasPermission('campaigns.audiences.export'),
            'canUpdate' => $request->user()->hasPermission('campaigns.audiences.rebuild'),
        ]);
    }

    /**
     * Rebuild the list from the participant rows and the enquiries.
     *
     * Needed because the contact list arrived after people had already registered.
     * Without it, every entry made before consent existed would be invisible.
     * Never grants consent that was not recorded, so running it cannot enlarge who
     * may be contacted.
     */
    public function rebuild(Request $request)
    {
        $result = CampaignAudience::rebuild();

        AdminLogger::activity(
            'campaigns.audience-rebuild',
            sprintf('Rebuilt the contact list: %d contacts, %d consented.', $result['contacts'], $result['consented']),
        );

        return back()->with('status', sprintf(
            'List rebuilt. %d contacts in total, %d newly added, %d have agreed to be contacted.',
            $result['contacts'],
            $result['created'],
            $result['consented'],
        ));
    }

    /**
     * The list as a CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        AdminLogger::activity('campaigns.audience-export', 'Exported the campaign contact list.');

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Name', 'Email', 'Telephone',
                'Email Consent', 'SMS Consent', 'Consented At', 'Source',
                'Unsubscribed At', 'Bounced At', 'First Event', 'Last Seen',
            ]);

            CampaignContact::with('firstEvent')->orderBy('id')->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $contact) {
                    fputcsv($handle, [
                        $contact->name ?? '',
                        $contact->email ?? '',
                        $contact->phone ?? '',
                        $contact->consent_email ? 'yes' : 'no',
                        $contact->consent_sms ? 'yes' : 'no',
                        $contact->consented_at?->toDateTimeString() ?? '',
                        $contact->consent_source ?? '',
                        $contact->unsubscribed_at?->toDateTimeString() ?? '',
                        $contact->bounced_at?->toDateTimeString() ?? '',
                        $contact->firstEvent?->title ?? '',
                        $contact->last_seen_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, sprintf('campaign-contacts-%s.csv', now()->format('Y-m-d-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Everyone who must not be sent to, and why.
     */
    public function suppression(Request $request)
    {
        return view('admin.campaign.suppression', [
            'contacts' => CampaignContact::query()
                ->suppressed()
                ->orderByDesc('unsubscribed_at')
                ->orderByDesc('bounced_at')
                ->paginate(self::PER_PAGE),
            'counts' => [
                'unsubscribed' => CampaignContact::whereNotNull('unsubscribed_at')->count(),
                'bounced' => CampaignContact::whereNotNull('bounced_at')->count(),
                'complained' => CampaignContact::whereNotNull('complained_at')->count(),
            ],

            // Adding somebody and putting them back are opposite acts, so they are
            // separate permissions. Overruling a person's own unsubscribe is the
            // more serious of the two.
            'canAdd' => $request->user()->hasPermission('campaigns.suppression.add'),
            'canRestore' => $request->user()->hasPermission('campaigns.suppression.resubscribe'),
        ]);
    }

    /**
     * Add an address to the suppression list by hand.
     *
     * For somebody who asks to be removed by telephone or in person. Recorded the
     * same way as a pressed link so there is one list to check, not two.
     */
    public function suppress(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'reason' => ['nullable', 'string', 'max:190'],
        ], [
            'identifier.required' => 'Enter the email address or telephone number to suppress.',
        ]);

        $value = trim($data['identifier']);
        $phone = \App\Support\PhoneNumber::toInternational($value);

        $contact = CampaignContact::where('email', mb_strtolower($value))
            ->when($phone !== null, fn (Builder $q) => $q->orWhere('phone', $phone))
            ->first();

        if ($contact === null) {
            // Created rather than refused. Somebody who asks not to be contacted
            // should be on the list whether or not they were ever on the other one,
            // so a future import cannot quietly add them.
            $contact = CampaignContact::create([
                'email' => str_contains($value, '@') ? mb_strtolower($value) : null,
                'phone' => $phone,
                'consent_source' => CampaignContact::SOURCE_ADMIN,
            ]);
        }

        $contact->unsubscribe($data['reason'] ?: 'Added by an administrator');

        AdminLogger::activity(
            'campaigns.suppress',
            sprintf('Suppressed %s from campaigns.', $contact->label()),
        );

        return back()->with('status', sprintf('%s will not be sent any campaign.', $contact->label()));
    }

    /**
     * Put somebody back on the list, at their own request.
     */
    public function resubscribe(Request $request, CampaignContact $contact)
    {
        $contact->update([
            'unsubscribed_at' => null,
            'unsubscribe_reason' => null,
            'consent_email' => filled($contact->email),
            'consent_sms' => filled($contact->phone),
            'consented_at' => now(),
            'consent_source' => CampaignContact::SOURCE_ADMIN,
        ]);

        AdminLogger::activity(
            'campaigns.resubscribe',
            sprintf('Put %s back on the campaign list.', $contact->label()),
        );

        AdminLogger::audit($contact, 'campaign.resubscribed', ['unsubscribed' => true], [
            'by' => $request->user()->name,
            'note' => 'Only correct when the person asked for it.',
        ]);

        return back()->with('status', sprintf(
            '%s is back on the list. Only do this when they asked to be.',
            $contact->label(),
        ));
    }
}
