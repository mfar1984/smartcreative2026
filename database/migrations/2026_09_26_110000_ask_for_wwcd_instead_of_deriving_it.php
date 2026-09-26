<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWCD becomes something the operator chooses.
 *
 * It was worked out from the placement: finish a fixture first, get the chicken dinner.
 * That is wrong, and it is wrong in a way the operator could not correct. Finishing top
 * of the table and being last squad standing are two different facts. A winner can be
 * penalised after the fact and the placement handed on without the dinner going with
 * it. A fixture can be abandoned, leaving a table and no winner. A squad can be given
 * first place by ruling rather than by surviving.
 *
 * It is counted even where it is worth no points, and a count is the first thing
 * compared when two squads finish level, so getting it wrong decides placings.
 *
 * Two jobs here, and the second matters as much as the first. The rules are repointed
 * at an asked-for figure, and every result already entered has that figure written from
 * the placement it used to be read from. Without the backfill, the next time a rule is
 * re-saved every past fixture would quietly lose its WWCD, because the old rows carry no
 * such input and the bonus would find nothing to fire on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ruleIds = $this->repointRules(
            fromSource: 'placement',
            toSource: 'wwcd',
            addInput: true,
        );

        if ($ruleIds !== []) {
            $this->backfillEntrants($ruleIds);
        }
    }

    /**
     * Put it back the way it was, and leave the backfilled figures alone.
     *
     * A stored `wwcd` input nobody reads is harmless, and deleting it would throw away
     * any choice an operator had since made by hand.
     */
    public function down(): void
    {
        $this->repointRules(
            fromSource: 'wwcd',
            toSource: 'placement',
            addInput: false,
        );
    }

    /**
     * @return array<int, int> ids of the rules that were changed
     */
    private function repointRules(string $fromSource, string $toSource, bool $addInput): array
    {
        $changed = [];

        $rules = DB::table('point_rules')
            ->where('kind', 'battle_royale')
            ->get(['id', 'components', 'inputs']);

        foreach ($rules as $rule) {
            $components = json_decode((string) $rule->components, true) ?: [];
            $inputs = json_decode((string) $rule->inputs, true) ?: [];
            $touched = false;

            foreach ($components as $index => $component) {
                if (($component['key'] ?? null) !== 'wwcd') {
                    continue;
                }

                // Only the shape this migration knows about. A profile somebody has
                // already changed by hand is left exactly as they left it.
                if (($component['when']['source'] ?? null) !== $fromSource) {
                    continue;
                }

                $components[$index]['when'] = ['source' => $toSource, 'equals' => 1];
                $touched = true;
            }

            if (! $touched) {
                continue;
            }

            $inputs = $addInput
                ? $this->withWwcdInput($inputs)
                : array_values(array_filter(
                    $inputs,
                    fn (array $input) => ($input['key'] ?? null) !== 'wwcd',
                ));

            DB::table('point_rules')->where('id', $rule->id)->update([
                'components' => json_encode($components),
                'inputs' => json_encode($inputs),
                'updated_at' => now(),
            ]);

            $changed[] = (int) $rule->id;
        }

        return $changed;
    }

    /**
     * Add the WWCD box, in front of the head count so the form reads in the order a
     * result screen does: placement, kills, who won, how many played.
     *
     * @param  array<int, array<string, mixed>>  $inputs
     * @return array<int, array<string, mixed>>
     */
    private function withWwcdInput(array $inputs): array
    {
        foreach ($inputs as $input) {
            if (($input['key'] ?? null) === 'wwcd') {
                return $inputs;
            }
        }

        $definition = [
            'key' => 'wwcd',
            'label' => 'WWCD',
            'type' => 'toggle',
            'required' => false,
            'single_in_match' => true,
        ];

        $out = [];
        $placed = false;

        foreach ($inputs as $input) {
            if (! $placed && ($input['key'] ?? null) === 'players_present') {
                $out[] = $definition;
                $placed = true;
            }

            $out[] = $input;
        }

        if (! $placed) {
            $out[] = $definition;
        }

        return $out;
    }

    /**
     * Write the figure onto every result already entered against these rules.
     *
     * Read from the placement it used to be derived from, so nothing that has been
     * scored changes meaning. A row with no placement is left alone: there is nothing to
     * read it from, and guessing would invent a result.
     *
     * @param  array<int, int>  $ruleIds
     */
    private function backfillEntrants(array $ruleIds): void
    {
        $matchIds = DB::table('tournament_matches')
            ->whereIn('tournament_id', function ($query) use ($ruleIds) {
                $query->select('id')->from('tournaments')->whereIn('point_rule_id', $ruleIds);
            })
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return;
        }

        DB::table('tournament_match_entrants')
            ->whereIn('tournament_match_id', $matchIds)
            ->whereNotNull('inputs')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $inputs = json_decode((string) $row->inputs, true);

                    if (! is_array($inputs)
                        || array_key_exists('wwcd', $inputs)
                        || ! array_key_exists('placement', $inputs)
                        || $inputs['placement'] === null) {
                        continue;
                    }

                    $inputs['wwcd'] = (int) $inputs['placement'] === 1 ? 1 : 0;

                    DB::table('tournament_match_entrants')
                        ->where('id', $row->id)
                        ->update(['inputs' => json_encode($inputs)]);
                }
            });
    }
};
