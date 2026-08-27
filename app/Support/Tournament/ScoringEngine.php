<?php

namespace App\Support\Tournament;

use App\Models\PointRule;

/**
 * Turns what an operator typed into points.
 *
 * This class does not know what PUBG is, nor badminton, nor aerobics. It knows
 * five component types. Every sport is expressed as a combination of those five in
 * a PointRule, which is why adding a sport needs no code here.
 *
 * Deliberately free of Eloquent beyond reading the profile, so the arithmetic can
 * be tested without a database.
 */
final class ScoringEngine
{
    /**
     * Score one competitor in one match.
     *
     * @param  array<string, mixed>  $inputs  keyed by input key: placement, kills,
     *                                        players_present, judges, and so on
     * @return array{
     *     points: float,
     *     components: array<string, float>,
     *     counts: array<string, int>,
     *     disqualified: bool
     * }
     */
    public function score(PointRule $rule, array $inputs): array
    {
        return $this->scoreComponents($rule->components ?? [], $inputs);
    }

    /**
     * Score one player in one match, using the profile's separate player components.
     *
     * This is a second, wholly separate ledger. Nothing computed here is ever added
     * to a team total, and nothing from the team ledger is ever added here. That
     * separation is what lets player scoring stay genuinely optional: a tournament
     * must be able to reach a published podium without a single player figure, and
     * it could not if these points fed into the team's.
     *
     * @param  array<string, mixed>  $inputs  keyed by player input key: kills, knocks, damage
     * @return array{
     *     points: float,
     *     components: array<string, float>,
     *     counts: array<string, int>,
     *     disqualified: bool
     * }
     */
    public function scorePlayer(PointRule $rule, array $inputs): array
    {
        return $this->scoreComponents($rule->player_components ?? [], $inputs);
    }

    /**
     * The arithmetic, shared by both ledgers.
     *
     * Team scoring and player scoring differ only in which list of components they
     * hand over. There is no second engine, and no component type exists for players
     * that does not exist for teams.
     *
     * @param  array<int, array<string, mixed>>  $componentList
     * @param  array<string, mixed>  $inputs
     * @return array{
     *     points: float,
     *     components: array<string, float>,
     *     counts: array<string, int>,
     *     disqualified: bool
     * }
     */
    private function scoreComponents(array $componentList, array $inputs): array
    {
        $components = [];
        $counts = [];
        $disqualified = false;

        foreach ($componentList as $component) {
            $key = $component['key'] ?? null;

            if ($key === null) {
                continue;
            }

            $result = $this->evaluate($component, $inputs);

            $components[$key] = $result['points'];

            /*
             | Counts are kept apart from points on purpose.
             |
             | A WWCD in the PMPL profile is worth zero points but is the first
             | tie-break. If it were only a number of points, it would vanish into a
             | zero and the tie-break would have nothing to compare.
             */
            if ($result['count'] !== null) {
                $counts[$key] = $result['count'];
            }

            $disqualified = $disqualified || $result['disqualified'];
        }

        return [
            'points' => round(array_sum($components), 3),
            'components' => $components,
            'counts' => $counts,
            'disqualified' => $disqualified,
        ];
    }

    /**
     * Score a whole race at once, because a finishing position only exists
     * relative to everybody else.
     *
     * @param  array<int|string, array<string, mixed>>  $inputsByEntrant
     * @return array<int|string, array{points: float, components: array<string, float>,
     *                                 counts: array<string, int>, disqualified: bool,
     *                                 inputs: array<string, mixed>}>
     */
    public function scoreRace(PointRule $rule, array $inputsByEntrant, string $timeKey = 'finish_time'): array
    {
        /*
         | Anybody without a time did not finish, so they are ranked after everybody
         | who did rather than being treated as having finished in zero seconds.
         */
        $finishers = [];
        $nonFinishers = [];

        foreach ($inputsByEntrant as $entrantKey => $inputs) {
            $time = $this->seconds($inputs[$timeKey] ?? null);

            $time === null
                ? $nonFinishers[$entrantKey] = $inputs
                : $finishers[$entrantKey] = $time;
        }

        asort($finishers);

        $scored = [];
        $placement = 0;
        $previousTime = null;
        $seenSoFar = 0;

        foreach ($finishers as $entrantKey => $time) {
            $seenSoFar++;

            // Equal times share a placement, and the next placement skips forward,
            // the way a race result is actually written up.
            if ($time !== $previousTime) {
                $placement = $seenSoFar;
                $previousTime = $time;
            }

            $inputs = $inputsByEntrant[$entrantKey];
            $inputs['placement'] = $placement;

            $scored[$entrantKey] = $this->score($rule, $inputs) + ['inputs' => $inputs];
        }

        foreach ($nonFinishers as $entrantKey => $inputs) {
            $inputs['placement'] = null;

            $scored[$entrantKey] = [
                'points' => 0.0,
                'components' => [],
                'counts' => [],
                'disqualified' => false,
                'inputs' => $inputs,
            ];
        }

        return $scored;
    }

    /* ---------------------------------------------------------------------
     | One component at a time
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function evaluate(array $component, array $inputs): array
    {
        return match ($component['type'] ?? null) {
            PointRule::TYPE_TABLE => $this->table($component, $inputs),
            PointRule::TYPE_PER_UNIT => $this->perUnit($component, $inputs),
            PointRule::TYPE_BONUS => $this->bonus($component, $inputs),
            PointRule::TYPE_PENALTY_TABLE => $this->penaltyTable($component, $inputs),
            PointRule::TYPE_AGGREGATE => $this->aggregate($component, $inputs),
            default => ['points' => 0.0, 'count' => null, 'disqualified' => false],
        };
    }

    /**
     * A position maps to points. Anything past the end of the table earns nothing,
     * which is how PUBG treats 9th to 16th place.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function table(array $component, array $inputs): array
    {
        $value = $inputs[$component['source'] ?? ''] ?? null;

        if ($value === null || $value === '') {
            return ['points' => 0.0, 'count' => null, 'disqualified' => false];
        }

        $points = (float) ($component['values'][(string) (int) $value] ?? 0);

        return ['points' => $points, 'count' => null, 'disqualified' => false];
    }

    /**
     * Each unit earns points: one kill, one point.
     *
     * The count is carried as well as the points, so a tie-break on kills compares
     * kills rather than the points those kills happened to be worth.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function perUnit(array $component, array $inputs): array
    {
        $units = (int) ($inputs[$component['source'] ?? ''] ?? 0);
        $each = (float) ($component['value'] ?? 0);

        return [
            'points' => $units * $each,
            'count' => $units,
            'disqualified' => false,
        ];
    }

    /**
     * A condition earns points, and is counted whether or not it earns any.
     *
     * This is what makes a WWCD work: worth nothing, but the first thing compared
     * when two squads finish level.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function bonus(array $component, array $inputs): array
    {
        $when = $component['when'] ?? [];
        $actual = $inputs[$when['source'] ?? ''] ?? null;
        $met = false;

        if ($actual !== null && $actual !== '') {
            if (array_key_exists('equals', $when)) {
                $met = (int) $actual === (int) $when['equals'];
            } elseif (array_key_exists('at_most', $when)) {
                $met = (int) $actual <= (int) $when['at_most'];
            } elseif (array_key_exists('at_least', $when)) {
                $met = (int) $actual >= (int) $when['at_least'];
            }
        }

        return [
            'points' => $met ? (float) ($component['value'] ?? 0) : 0.0,
            'count' => $met ? 1 : 0,
            'disqualified' => false,
        ];
    }

    /**
     * A shortfall subtracts points, and at the bottom of the table it disqualifies.
     *
     * The values are stored already negative, so the sum in score() needs no
     * special case for penalties.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function penaltyTable(array $component, array $inputs): array
    {
        $source = $component['source'] ?? '';

        if (! array_key_exists($source, $inputs) || $inputs[$source] === null || $inputs[$source] === '') {
            return ['points' => 0.0, 'count' => null, 'disqualified' => false];
        }

        $present = (int) $inputs[$source];

        if (array_key_exists('disqualify_at', $component) && $present <= (int) $component['disqualify_at']) {
            return ['points' => 0.0, 'count' => null, 'disqualified' => true];
        }

        $points = (float) ($component['values'][(string) $present] ?? 0);

        return ['points' => $points, 'count' => null, 'disqualified' => false];
    }

    /**
     * Combine a panel's marks.
     *
     * trimmed_mean drops one highest and one lowest, which is how judged sports
     * stop a single generous or harsh judge deciding the result. It needs at least
     * three marks to mean anything, so below that it falls back to a plain mean.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $inputs
     * @return array{points: float, count: int|null, disqualified: bool}
     */
    private function aggregate(array $component, array $inputs): array
    {
        $marks = $inputs[$component['source'] ?? ''] ?? [];

        if (! is_array($marks)) {
            return ['points' => 0.0, 'count' => null, 'disqualified' => false];
        }

        $marks = array_values(array_map('floatval', array_filter(
            $marks,
            fn ($mark) => $mark !== null && $mark !== '',
        )));

        if ($marks === []) {
            return ['points' => 0.0, 'count' => 0, 'disqualified' => false];
        }

        $method = $component['method'] ?? 'mean';

        if ($method === 'trimmed_mean' && count($marks) >= 3) {
            sort($marks);
            array_shift($marks);
            array_pop($marks);
        }

        $points = $method === 'sum'
            ? array_sum($marks)
            : array_sum($marks) / count($marks);

        return [
            'points' => round($points, 3),
            'count' => count($marks),
            'disqualified' => false,
        ];
    }

    /**
     * Read a finishing time as seconds.
     *
     * Accepts a plain number, mm:ss, or hh:mm:ss, because a marshal at the finish
     * line writes whichever is natural and should not be corrected by the form.
     */
    private function seconds(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $parts = array_reverse(explode(':', (string) $value));

        if ($parts === [] || ! is_numeric($parts[0])) {
            return null;
        }

        $seconds = 0.0;

        foreach ($parts as $index => $part) {
            if (! is_numeric($part)) {
                return null;
            }

            $seconds += (float) $part * (60 ** $index);
        }

        return $seconds;
    }
}
