<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings shared by every tournament.
 *
 * These answer how the day is run: the gap between matches, how long a late team is
 * given, whether a screenshot is required, what maps a game may draw from, and what
 * the public site shows. What a result is worth is a different question, and it lives
 * in Point Rules.
 *
 * A new tournament copies these rather than pointing at them, so changing the default
 * buffer next month leaves a tournament already under way alone.
 */
class TournamentSettingsController extends Controller
{
    private const GROUP = 'tournament';

    /**
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        'match' => [
            'label' => 'Match Day',
            'icon' => 'clipboard',
            'title' => 'Match Day',
            'description' => 'The gap between matches, how long a late team is given, and whether a screenshot is required.',
            'accent' => 'blue',
        ],
        'maps' => [
            'label' => 'Maps',
            'icon' => 'globe',
            'title' => 'Map Pools',
            'description' => 'Which maps a game may draw from, and the rotation used when a stage is generated.',
            'accent' => 'green',
        ],
        'display' => [
            'label' => 'Public Display',
            'icon' => 'trophy',
            'title' => 'Public Display',
            'description' => 'What the website shows, and whether standings appear before a podium is published.',
            'accent' => 'purple',
        ],
    ];

    /**
     * Values used before anything has been saved.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'buffer_minutes' => 15,
        'lateness_minutes' => 10,
        'require_proof' => '0',
        'default_best_of' => '1,1,3,3,5',
        'map_pool' => "Erangel\nMiramar\nSanhok\nVikendi\nLivik",
        'map_rotation' => 'Erangel, Miramar, Erangel, Sanhok, Miramar',
        'public_rankings_live' => '1',
        'device_rule' => 'mobile_only',
    ];

    /** @var array<string, string> */
    public const DEVICE_RULES = [
        'mobile_only' => 'Mobile devices only',
        'emulator_allowed' => 'Emulators allowed',
        'any' => 'Any device',
    ];

    public function index(Request $request)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TAB_INTRO)
            ? (string) $request->query('tab')
            : 'match';

        return view('admin.tournament.settings', [
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $definition) => [
                    'label' => $definition['label'],
                    'icon' => $definition['icon'],
                ])
                ->all(),

            'activeTab' => $tab,
            'intro' => self::TAB_INTRO[$tab],
            'route' => 'admin.tournaments.settings',
            'values' => $this->values(),
            'deviceRules' => self::DEVICE_RULES,
            'canUpdate' => $request->user()->hasPermission('tournaments.settings.update'),
        ]);
    }

    public function update(Request $request, string $tab)
    {
        if (! array_key_exists($tab, self::TAB_INTRO)) {
            abort(404);
        }

        $data = $request->validate($this->rulesFor($tab), [
            'buffer_minutes.min' => 'A buffer under five minutes leaves no time to set the next match up.',
        ]);

        $before = $this->values();

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => self::GROUP . '.' . $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'group' => self::GROUP],
            );
        }

        AdminLogger::activity('tournaments.settings.update', sprintf('Updated tournament %s settings.', $tab));
        AdminLogger::audit(
            new Setting(['key' => self::GROUP . '.*', 'group' => self::GROUP]),
            'settings.updated',
            $before,
            $this->values(),
        );

        return redirect()
            ->route('admin.tournaments.settings', ['tab' => $tab])
            ->with('status', 'Settings saved. Tournaments already under way keep the rules they started with.');
    }

    /**
     * The current values, with defaults where nothing has been saved.
     *
     * @return array<string, mixed>
     */
    private function values(): array
    {
        $stored = Setting::where('group', self::GROUP)
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($value, $key) => [str_replace(self::GROUP . '.', '', $key) => $value])
            ->all();

        return array_merge(self::DEFAULTS, $stored);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(string $tab): array
    {
        return match ($tab) {
            'match' => [
                'buffer_minutes' => ['required', 'integer', 'min:5', 'max:180'],
                'lateness_minutes' => ['required', 'integer', 'min:0', 'max:120'],
                'require_proof' => ['nullable', 'boolean'],
                'default_best_of' => ['nullable', 'string', 'max:40'],
            ],
            'maps' => [
                'map_pool' => ['nullable', 'string', 'max:2000'],
                'map_rotation' => ['nullable', 'string', 'max:500'],
            ],
            default => [
                'public_rankings_live' => ['nullable', 'boolean'],
                'device_rule' => ['required', Rule::in(array_keys(self::DEVICE_RULES))],
            ],
        };
    }
}
