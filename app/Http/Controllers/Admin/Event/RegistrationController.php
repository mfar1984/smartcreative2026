<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventRequest;
use App\Models\Event;
use App\Services\AdminLogger;
use App\Services\EventAddonWriter;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RegistrationController extends Controller
{
    /**
     * Tab slug => label and icon.
     *
     * The three lifecycle tabs are worked out from the event dates rather than
     * a stored flag, so they can never disagree with the dates on screen.
     */
    public const TABS = [
        'register' => ['label' => 'Register Event', 'icon' => 'clipboard'],
        'ongoing' => ['label' => 'Ongoing', 'icon' => 'activity'],
        'completed' => ['label' => 'Completed', 'icon' => 'shield'],
        'cancel' => ['label' => 'Cancel', 'icon' => 'power'],
    ];

    private const PER_PAGE = 15;

    /**
     * Where posters live on the public disk.
     */
    private const POSTER_DIRECTORY = 'event-posters';

    /**
     * Where rulebook attachments live on the public disk.
     */
    private const RULES_DIRECTORY = 'event-rules';

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));

        $search = trim((string) $request->query('q'));
        $category = trim((string) $request->query('category'));

        $events = $this->scoped($tab)
            ->withCount('registrations')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            }))
            ->when($category !== '', fn (Builder $query) => $query->where('category', $category))
            ->orderBy('starts_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $this->counts();

        return view('admin.event.registration', [
            // Merge the row count into each tab so the tab bar can badge it.
            'tabs' => collect(self::TABS)
                ->map(fn (array $definition, string $slug) => $definition + ['count' => $counts[$slug] ?? 0])
                ->all(),
            'activeTab' => $tab,
            'events' => $events,
            'categories' => Event::query()->distinct()->orderBy('category')->pluck('category')->all(),
            'search' => $search,
            'category' => $category,
            'isFiltered' => $search !== '' || $category !== '',
            'canCreate' => $request->user()->hasPermission('events.create'),
            'canUpdate' => $request->user()->hasPermission('events.update'),
            'canDelete' => $request->user()->hasPermission('events.delete'),
        ]);
    }

    public function create()
    {
        return view('admin.event.form', $this->formData(new Event([
            'status' => Event::STATUS_DRAFT,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'seats_total' => 0,
            'min_players' => 1,
        ]), 'create'));
    }

    public function store(EventRequest $request, EventAddonWriter $addons)
    {
        $event = new Event($request->eventAttributes());
        $event->slug = $this->resolveSlug($request->input('slug'), $request->input('title'));
        $event->poster_path = $this->storePoster($request);
        $this->applyRulesFile($request, $event);
        $event->save();

        $addons->sync($event, $request->addonRows());

        AdminLogger::activity('events.create', sprintf('Created event %s.', $event->title));
        AdminLogger::audit($event, 'created', null, [
            'title' => $event->title,
            'slug' => $event->slug,
            'status' => $event->status,
            'registration_mode' => $event->registration_mode,
            'fee' => $event->fee,
        ]);

        return redirect()
            ->route('admin.event.registration.show', $event)
            ->with('status', sprintf('Event %s created.', $event->title));
    }

    public function show(Request $request, Event $event)
    {
        $event->load(['registrations' => fn ($query) => $query->with(['participants', 'addonLines'])->latest()]);

        return view('admin.event.show', [
            'event' => $event,
            'payment' => [
                'summary' => PaymentSettings::summary(),
                'ready' => PaymentSettings::isReady(),
                'currency' => PaymentSettings::currency(),
            ],
            'canUpdate' => $request->user()->hasPermission('events.update'),
            'canDelete' => $request->user()->hasPermission('events.delete'),
        ]);
    }

    public function edit(Event $event)
    {
        return view('admin.event.form', $this->formData($event, 'edit'));
    }

    public function update(EventRequest $request, Event $event, EventAddonWriter $addons)
    {
        $before = [
            'title' => $event->title,
            'status' => $event->status,
            'registration_mode' => $event->registration_mode,
            'fee' => $event->fee,
            'seats_total' => $event->seats_total,
            'addons' => $event->addons()->count(),
        ];

        $event->fill($request->eventAttributes());

        if ($request->filled('slug')) {
            $event->slug = $this->resolveSlug($request->input('slug'), $request->input('title'), $event->id);
        }

        $poster = $this->storePoster($request, $event);

        if ($poster !== null || $request->boolean('remove_poster')) {
            $event->poster_path = $poster;
        }

        $this->applyRulesFile($request, $event);

        $event->save();

        $addons->sync($event, $request->addonRows());

        AdminLogger::activity('events.update', sprintf('Updated event %s.', $event->title));
        AdminLogger::audit($event, 'updated', $before, [
            'title' => $event->title,
            'status' => $event->status,
            'registration_mode' => $event->registration_mode,
            'fee' => $event->fee,
            'seats_total' => $event->seats_total,
            'addons' => $event->addons()->count(),
        ]);

        return redirect()
            ->route('admin.event.registration.show', $event)
            ->with('status', sprintf('Event %s saved.', $event->title));
    }

    public function destroy(Event $event)
    {
        // Registrations hold personal data, so deleting an event that has any
        // is refused. Cancelling it is the intended path.
        $registrationCount = $event->registrations()->count();

        if ($registrationCount > 0) {
            return redirect()
                ->route('admin.event.registration.show', $event)
                ->withErrors([
                    'event' => sprintf(
                        '%s has %d %s attached. Set its status to Cancelled instead of deleting it.',
                        $event->title,
                        $registrationCount,
                        $registrationCount === 1 ? 'registration' : 'registrations',
                    ),
                ]);
        }

        AdminLogger::audit($event, 'deleted', [
            'title' => $event->title,
            'slug' => $event->slug,
        ], null);

        $title = $event->title;

        if ($event->poster_path) {
            Storage::disk('public')->delete($event->poster_path);
        }

        if ($event->rules_file_path) {
            Storage::disk('public')->delete($event->rules_file_path);
        }

        $event->delete();

        AdminLogger::activity('events.delete', sprintf('Deleted event %s.', $title));

        return redirect()
            ->route('admin.event.registration')
            ->with('status', sprintf('Event %s deleted.', $title));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function formData(Event $event, string $mode): array
    {
        // The builder walks the options of every add-on, so they are loaded up
        // front rather than one query per row.
        if ($event->exists) {
            $event->load('addons.variants');
        }

        return [
            'event' => $event,
            'mode' => $mode,
            'statuses' => Event::STATUSES,
            'modes' => Event::MODES,
            'categories' => Event::query()->distinct()->orderBy('category')->pluck('category')->all(),
            'roles' => ParticipantOptions::ROLES,
            'payment' => [
                'summary' => PaymentSettings::summary(),
                'ready' => PaymentSettings::isReady(),
                'currency' => PaymentSettings::currency(),
                'provider' => PaymentSettings::providerLabel(),
            ],
        ];
    }

    /**
     * Use the given slug when supplied, otherwise derive one from the title,
     * adding a numeric suffix until it is free.
     */
    private function resolveSlug(?string $slug, string $title, ?int $ignoreId = null): string
    {
        $base = filled($slug) ? str($slug)->slug()->toString() : str($title)->slug()->toString();
        $base = $base !== '' ? $base : 'event';

        $candidate = $base;
        $suffix = 1;

        while (Event::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }

    /**
     * Save an uploaded poster and return its path, or null when nothing was
     * uploaded. Replacing a poster removes the old file so the disk does not
     * fill with orphans.
     */
    private function storePoster(EventRequest $request, ?Event $event = null): ?string
    {
        if ($request->boolean('remove_poster') && $event?->poster_path) {
            Storage::disk('public')->delete($event->poster_path);

            return null;
        }

        if (! $request->hasFile('poster')) {
            return $event?->poster_path;
        }

        if ($event?->poster_path) {
            Storage::disk('public')->delete($event->poster_path);
        }

        return $request->file('poster')->store(self::POSTER_DIRECTORY, 'public');
    }

    /**
     * Apply a rulebook upload, a removal, or neither, to the given event.
     *
     * Written onto the model rather than returned because two columns move
     * together here: a path with no name, or a name with no path, would both be
     * broken states, and keeping the pair in one place is what stops that.
     * Called for a new event as well, where every branch simply finds nothing to
     * replace.
     */
    private function applyRulesFile(EventRequest $request, Event $event): void
    {
        $disk = Storage::disk('public');

        if ($request->boolean('remove_rules_file')) {
            if ($event->rules_file_path) {
                $disk->delete($event->rules_file_path);
            }

            $event->rules_file_path = null;
            $event->rules_file_name = null;

            return;
        }

        if (! $request->hasFile('rules_file')) {
            return;
        }

        // Replacing drops the old file so the disk does not fill with orphans.
        if ($event->rules_file_path) {
            $disk->delete($event->rules_file_path);
        }

        $file = $request->file('rules_file');

        $event->rules_file_path = $file->store(self::RULES_DIRECTORY, 'public');
        $event->rules_file_name = $this->displayFileName($file->getClientOriginalName());
    }

    /**
     * Reduce an uploaded filename to something safe to store and show.
     *
     * The name is display only and never reaches the filesystem, so this is not
     * guarding a path. It strips directory parts anyway in case a later change
     * does build a path from it, drops control characters, and trims to the
     * column width so a long name cannot fail the insert.
     */
    private function displayFileName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return 'rules.pdf';
        }

        return str($name)->limit(250, '')->toString();
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'register';
    }

    private function scoped(string $tab): Builder
    {
        return match ($tab) {
            'ongoing' => Event::query()->ongoing(),
            'completed' => Event::query()->completed(),
            'cancel' => Event::query()->cancelled(),
            default => Event::query()->upcoming(),
        };
    }

    /**
     * Row count per tab, shown as a badge on the tab bar.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'register' => Event::query()->upcoming()->count(),
            'ongoing' => Event::query()->ongoing()->count(),
            'completed' => Event::query()->completed()->count(),
            'cancel' => Event::query()->cancelled()->count(),
        ];
    }
}
