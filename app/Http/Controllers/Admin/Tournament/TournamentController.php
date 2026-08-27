<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PointRule;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Services\AdminLogger;
use App\Support\Tournament\EntrantImporter;
use App\Support\Tournament\TournamentProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tournaments, their entrants and their seeding.
 *
 * Several tournaments may be ongoing at once, including several on one event, so
 * every query here is scoped by tournament and nothing is cached between requests.
 */
class TournamentController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * What each tab holds, shown above its table.
     *
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        Tournament::STATUS_SETUP => [
            'label' => 'Setup',
            'icon' => 'wrench',
            'title' => 'Setup',
            'description' => 'Not started. Entrants, seeds and the draw can still be changed.',
            'accent' => 'amber',
        ],
        Tournament::STATUS_ONGOING => [
            'label' => 'Ongoing',
            'icon' => 'activity',
            'title' => 'Ongoing',
            'description' => 'Being played. Results go in on the Matches screen.',
            'accent' => 'blue',
        ],
        Tournament::STATUS_COMPLETED => [
            'label' => 'Completed',
            'icon' => 'shield',
            'title' => 'Completed',
            'description' => 'Every match played, waiting to be published to the website.',
            'accent' => 'green',
        ],
        Tournament::STATUS_PUBLISHED => [
            'label' => 'Published',
            'icon' => 'trophy',
            'title' => 'Published',
            'description' => 'The podium is on the public site, frozen as it was published.',
            'accent' => 'purple',
        ],
    ];

    /* ---------------------------------------------------------------------
     | List
     * ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $status = $this->resolveStatus($request->query('tab'));
        $search = trim((string) $request->query('q', ''));
        $eventId = (string) $request->query('event', '');

        $query = Tournament::query()
            ->with(['event:id,title', 'pointRule:id,name', 'creator:id,name'])
            ->withCount(['entrants' => fn ($q) => $q->where('status', TournamentEntrant::STATUS_ACTIVE)])
            ->where('status', $status)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($eventId !== '', fn ($q) => $q->where('event_id', $eventId));

        $counts = Tournament::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.tournament.index', [
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $tab, string $slug) => [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'count' => (int) ($counts[$slug] ?? 0),
                ])
                ->all(),

            'activeTab' => $status,
            'intro' => self::TAB_INTRO[$status],
            'route' => 'admin.tournaments.index',
            'tournaments' => $query->latest()->paginate(self::PER_PAGE)->withQueryString(),
            'events' => Event::whereHas('registrations')->orderByDesc('starts_at')->pluck('title', 'id'),
            'filters' => compact('search', 'eventId'),
            'isFiltered' => $search !== '' || $eventId !== '',
            'canCreate' => $request->user()->hasPermission('tournaments.create'),

            // Said on the list because concurrency is the point: two of these can be
            // ongoing at once and the operator should see that rather than assume it.
            'ongoingTotal' => (int) ($counts[Tournament::STATUS_ONGOING] ?? 0),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Create and edit
     * ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        return view('admin.tournament.form', $this->formData(new Tournament([
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'event_id' => $request->query('event'),
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $tournament = Tournament::create($data + [
            'status' => Tournament::STATUS_SETUP,
            'created_by' => $request->user()->id,

            /*
             | The shared settings are copied, not referenced. Changing the default
             | buffer next month must not alter a tournament already under way.
             */
            'settings' => $this->defaultSettings(),
        ]);

        AdminLogger::activity('tournaments.create', sprintf(
            'Created tournament %s on %s.',
            $tournament->name,
            $tournament->event?->title ?? 'an event',
        ));

        return redirect()
            ->route('admin.tournaments.show', $tournament)
            ->with('status', 'Tournament created. Next: add the entrants.');
    }

    public function edit(Tournament $tournament)
    {
        return view('admin.tournament.form', $this->formData($tournament));
    }

    public function update(Request $request, Tournament $tournament)
    {
        /*
         | The format and the point rule decide the shape of every fixture and every
         | score column. Changing them once a draw exists would leave results that
         | mean something different from what was recorded.
         */
        $locked = $tournament->hasDraw();

        $data = $this->validated($request, $tournament);

        if ($locked) {
            unset($data['format'], $data['point_rule_id'], $data['event_id']);
        }

        $tournament->update($data);

        AdminLogger::activity('tournaments.update', sprintf('Updated tournament %s.', $tournament->name));

        return redirect()
            ->route('admin.tournaments.show', $tournament)
            ->with('status', $locked
                ? 'Saved. The format and point rule are fixed because a draw already exists.'
                : 'Saved.');
    }

    public function destroy(Tournament $tournament)
    {
        if (! $tournament->isSetup()) {
            return back()->withErrors([
                'tournament' => 'A tournament that has started is a record of what was played and cannot be deleted.',
            ]);
        }

        $name = $tournament->name;
        $tournament->delete();

        AdminLogger::activity('tournaments.delete', sprintf('Deleted tournament %s.', $name));

        return redirect()
            ->route('admin.tournaments.index')
            ->with('status', sprintf('Tournament %s deleted.', $name));
    }

    /* ---------------------------------------------------------------------
     | Detail
     * ------------------------------------------------------------------ */

    public function show(Request $request, Tournament $tournament, TournamentProgress $progress, EntrantImporter $importer)
    {
        $tab = in_array($request->query('tab'), ['entrants', 'stages', 'settings'], true)
            ? (string) $request->query('tab')
            : 'progress';

        $tournament->load(['event:id,title,slug,min_players,max_players,requires_ign', 'pointRule', 'creator:id,name']);

        $entrants = $tournament->entrants()
            ->with(['registration.participants'])
            ->orderByRaw('seed IS NULL, seed')
            ->get();

        $survey = $tab === 'entrants' ? $importer->survey($tournament) : null;

        $stages = collect();

        if ($tab === 'stages') {
            $factory = app(\App\Support\Tournament\Draw\DrawFactory::class);

            $stages = $tournament->stages()
                ->withCount(['matches', 'groups'])
                ->get()
                // The reason a stage cannot be drawn, worked out per stage so the
                // screen can print it rather than leaving a dead button.
                ->each(fn (\App\Models\TournamentStage $stage) => $stage->refusal = $stage->hasDraw()
                    ? null
                    : $factory->refusal($stage));
        }

        return view('admin.tournament.show', [
            'tournament' => $tournament,
            'activeTab' => $tab,
            'steps' => $progress->steps($tournament),
            'nextAction' => $progress->nextAction($tournament),
            'drawState' => $progress->canGenerateDraw($tournament),
            'entrants' => $entrants,
            'stages' => $stages,
            'suggestedStageType' => $this->suggestedStageType($tournament),
            'survey' => $survey,
            'canUpdate' => $request->user()->hasPermission('tournaments.update'),
            'canDelete' => $request->user()->hasPermission('tournaments.delete'),
            'canGenerate' => $request->user()->hasPermission('tournaments.matches.generate'),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Entrants
     * ------------------------------------------------------------------ */

    public function importEntrants(Request $request, Tournament $tournament, EntrantImporter $importer)
    {
        if (! $tournament->isEditable()) {
            return back()->withErrors(['entrants' => $this->lockedMessage($tournament)]);
        }

        $result = $importer->import($tournament);

        if ($result['imported'] === 0) {
            return back()->withErrors([
                'entrants' => $result['skipped'] === 0
                    ? 'Every eligible registration is already an entrant.'
                    : sprintf(
                        'Nothing could be imported. %s.',
                        $this->reasonSentence($result['reasons']),
                    ),
            ]);
        }

        AdminLogger::activity('tournaments.update', sprintf(
            'Imported %d entrants into tournament %s.',
            $result['imported'],
            $tournament->name,
        ));

        return back()->with('status', $result['skipped'] === 0
            ? sprintf('%d entrants imported.', $result['imported'])
            : sprintf(
                '%d entrants imported. %d left out: %s.',
                $result['imported'],
                $result['skipped'],
                $this->reasonSentence($result['reasons']),
            ));
    }

    public function addEntrant(Request $request, Tournament $tournament, EntrantImporter $importer)
    {
        if (! $tournament->isEditable()) {
            return back()->withErrors(['entrants' => $this->lockedMessage($tournament)]);
        }

        $data = $request->validate([
            'event_registration_id' => ['required', 'exists:event_registrations,id'],
        ]);

        $registration = EventRegistration::findOrFail($data['event_registration_id']);

        if ($registration->event_id !== $tournament->event_id) {
            return back()->withErrors(['entrants' => 'That entry belongs to a different event.']);
        }

        if ($tournament->entrants()->where('event_registration_id', $registration->id)->exists()) {
            return back()->withErrors(['entrants' => 'That entry is already in this tournament.']);
        }

        $importer->addByHand($tournament, $registration);

        AdminLogger::activity('tournaments.update', sprintf(
            'Added %s to tournament %s by hand.',
            $registration->team_name ?: $registration->reference,
            $tournament->name,
        ));

        return back()->with('status', sprintf(
            '%s added by hand.',
            $registration->team_name ?: $registration->reference,
        ));
    }

    public function removeEntrant(Tournament $tournament, TournamentEntrant $entrant)
    {
        if ($entrant->tournament_id !== $tournament->id) {
            abort(404);
        }

        if (! $tournament->isEditable()) {
            return back()->withErrors([
                'entrants' => 'A draw already exists, so entrants cannot be removed. Withdraw or disqualify instead.',
            ]);
        }

        $name = $entrant->displayName();
        $entrant->delete();

        AdminLogger::activity('tournaments.update', sprintf(
            'Removed %s from tournament %s.',
            $name,
            $tournament->name,
        ));

        return back()->with('status', sprintf('%s removed.', $name));
    }

    /* ---------------------------------------------------------------------
     | Seeding
     * ------------------------------------------------------------------ */

    public function seed(Request $request, Tournament $tournament)
    {
        if (! $tournament->isEditable()) {
            return back()->withErrors(['seeds' => $this->lockedMessage($tournament)]);
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Tournament::SEEDING_METHODS))],
            'seeds' => ['array'],
            'seeds.*' => ['nullable', 'integer', 'min:1'],
        ]);

        $active = $tournament->entrants()
            ->where('status', TournamentEntrant::STATUS_ACTIVE)
            ->with('registration:id,created_at')
            ->get();

        if ($active->count() < 2) {
            return back()->withErrors(['seeds' => 'At least two active entrants are needed before seeding.']);
        }

        $ordered = match ($data['method']) {
            Tournament::SEEDING_RANDOM => $active->shuffle(),
            Tournament::SEEDING_REGISTRATION => $active->sortBy('event_registration_id'),
            default => $this->manualOrder($active, $data['seeds'] ?? []),
        };

        /*
         | Seeds are cleared before being written. The column is unique per
         | tournament, so assigning 1 to a second row while the first still holds it
         | would collide halfway through.
         */
        DB::transaction(function () use ($tournament, $ordered) {
            $tournament->entrants()->update(['seed' => null]);

            foreach ($ordered->values() as $index => $entrant) {
                $entrant->update(['seed' => $index + 1]);
            }

            $tournament->update([
                'seeding_method' => request('method'),
                'seeded_at' => now(),
            ]);
        });

        AdminLogger::activity('tournaments.update', sprintf(
            'Seeded tournament %s by %s.',
            $tournament->name,
            Tournament::SEEDING_METHODS[$data['method']] ?? $data['method'],
        ));

        AdminLogger::audit($tournament, 'tournament.seeded', null, [
            'method' => $data['method'],
            'entrants' => $ordered->count(),
        ]);

        return back()->with('status', match ($data['method']) {
            Tournament::SEEDING_RANDOM => sprintf('%d entrants drawn at random.', $ordered->count()),
            Tournament::SEEDING_REGISTRATION => sprintf('%d entrants seeded in registration order.', $ordered->count()),
            default => sprintf('%d seeds saved.', $ordered->count()),
        });
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Put the entrants in the order the operator typed, with anything they left
     * blank falling to the end in its existing order.
     *
     * @param  \Illuminate\Support\Collection<int, TournamentEntrant>  $active
     * @param  array<int|string, mixed>  $seeds
     * @return \Illuminate\Support\Collection<int, TournamentEntrant>
     */
    private function manualOrder($active, array $seeds)
    {
        return $active->sortBy(fn (TournamentEntrant $entrant) => [
            isset($seeds[$entrant->id]) && $seeds[$entrant->id] !== null ? 0 : 1,
            (int) ($seeds[$entrant->id] ?? PHP_INT_MAX),
            $entrant->seed ?? PHP_INT_MAX,
            $entrant->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Tournament $tournament = null): array
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'name' => ['required', 'string', 'max:190'],
            'format' => ['required', Rule::in(array_keys(Tournament::FORMATS))],
            'point_rule_id' => ['required', 'exists:point_rules,id'],
            'seeding_method' => ['required', Rule::in(array_keys(Tournament::SEEDING_METHODS))],
        ], [
            'name.required' => 'Give the tournament a name so you can tell it from the others on this event.',
            'point_rule_id.required' => 'Choose the scoring this tournament uses.',
        ]);

        /*
         | The scoring family has to match the format. A bracket profile cannot score
         | a battle royale: it has no placement table and nothing to add up.
         */
        $rule = PointRule::find($data['point_rule_id']);
        $needed = PointRule::kindForFormat($data['format']);

        if ($rule !== null && $rule->kind !== $needed) {
            $compatible = PointRule::where('kind', $needed)->where('is_active', true)->pluck('name');

            throw ValidationException::withMessages([
                'point_rule_id' => sprintf(
                    '%s scores %s, but %s needs %s scoring. Compatible rules: %s.',
                    $rule->name,
                    $rule->kindLabel(),
                    Tournament::FORMATS[$data['format']],
                    PointRule::KINDS[$needed],
                    $compatible->isEmpty() ? 'none yet, create one first' : $compatible->implode(', '),
                ),
            ]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Tournament $tournament): array
    {
        return [
            'tournament' => $tournament,
            'mode' => $tournament->exists ? 'edit' : 'create',
            'events' => Event::whereHas('registrations')->orderByDesc('starts_at')->pluck('title', 'id'),
            'formats' => Tournament::FORMATS,
            'formatNotes' => Tournament::FORMAT_NOTES,
            'seedingMethods' => Tournament::SEEDING_METHODS,

            // Grouped by kind so the form can narrow the list as the format changes,
            // rather than offering a rule that will be refused on save.
            'rulesByKind' => PointRule::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->groupBy('kind')
                ->map(fn ($rules) => $rules->pluck('name', 'id'))
                ->all(),

            'kindForFormat' => collect(Tournament::FORMATS)
                ->mapWithKeys(fn ($label, $format) => [$format => PointRule::kindForFormat($format)])
                ->all(),

            'locked' => $tournament->hasDraw(),
        ];
    }

    /**
     * The shared defaults a new tournament starts with.
     *
     * Held here until the Settings screen stores them, so a tournament created today
     * already carries the buffer and lateness rules rather than nulls.
     *
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        $stored = \App\Models\Setting::where('group', 'tournament')
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($value, $key) => [str_replace('tournament.', '', $key) => $value])
            ->all();

        $values = array_merge(TournamentSettingsController::DEFAULTS, $stored);

        return [
            'buffer_minutes' => (int) $values['buffer_minutes'],
            'lateness_minutes' => (int) $values['lateness_minutes'],
            'require_proof' => (bool) (int) $values['require_proof'],
            'public_rankings_live' => (bool) (int) $values['public_rankings_live'],
            'device_rule' => (string) $values['device_rule'],

            // Stored as a comma list on the settings screen because that is how an
            // operator writes a rotation; held as a list here because that is how the
            // lobby generator cycles it.
            'map_rotation' => collect(explode(',', (string) $values['map_rotation']))
                ->map(fn ($map) => trim($map))
                ->filter()
                ->values()
                ->all(),

            'default_best_of' => collect(explode(',', (string) $values['default_best_of']))
                ->map(fn ($value) => (int) trim($value))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function lockedMessage(Tournament $tournament): string
    {
        return $tournament->hasDraw()
            ? 'A draw already exists. Discard it before changing who is in the tournament.'
            : sprintf('This tournament is %s, so its entrants are fixed.', strtolower($tournament->statusLabel()));
    }

    /**
     * @param  array<string, int>  $reasons
     */
    private function reasonSentence(array $reasons): string
    {
        if ($reasons === []) {
            return 'nothing was eligible';
        }

        return collect($reasons)
            ->map(fn (int $count, string $reason) => sprintf('%d %s', $count, strtolower($reason)))
            ->implode(', ');
    }

    /**
     * The stage kind that matches the tournament's format, preselected on the form.
     *
     * A guess, not a rule: a group stage tournament still needs a bracket stage after
     * its groups, so the operator can pick anything.
     */
    private function suggestedStageType(Tournament $tournament): string
    {
        return match ($tournament->format) {
            Tournament::FORMAT_BATTLE_ROYALE => \App\Models\TournamentStage::TYPE_LOBBY,
            Tournament::FORMAT_RACE, Tournament::FORMAT_JUDGED => \App\Models\TournamentStage::TYPE_HEAT,
            Tournament::FORMAT_GROUP_SINGLE_ELIM => $tournament->stages()->exists()
                ? \App\Models\TournamentStage::TYPE_BRACKET
                : \App\Models\TournamentStage::TYPE_GROUP,
            default => \App\Models\TournamentStage::TYPE_BRACKET,
        };
    }

    private function resolveStatus(?string $value): string
    {
        return array_key_exists((string) $value, self::TAB_INTRO)
            ? (string) $value
            : Tournament::STATUS_SETUP;
    }
}
