<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Contracts\MatchAwardReader;
use App\Contracts\PlayerSheetReader;
use App\Contracts\ScoreSheetReader;
use App\Http\Controllers\Controller;
use App\Models\PointRule;
use App\Models\TournamentMatch;
use App\Services\AdminLogger;
use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetImage;
use App\Support\Tournament\MatchAwardMatcher;
use App\Support\Tournament\MatchAwardSheet;
use App\Support\Tournament\MatchRoster;
use App\Support\Tournament\PlayerSheetMatcher;
use App\Support\Tournament\ScoreSheetFields;
use App\Support\Tournament\ScoreSheetMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reading a result off a photograph: the results table, the game's Star of the
 * Match screen, and the game's own per-player result screen.
 *
 * Its own controller rather than another method on MatchController, which is
 * already the largest file in the module: nothing here shares a line with saving a
 * score, and that is the point of it being separate. The three reads sit together
 * because they are the same promise with a different screen in front of it.
 *
 * THIS ENDPOINT WRITES NOTHING. It answers with what it read and what it matched
 * that to; the figures go into the form in the operator's browser and are saved by
 * the same Save button, the same validation and the same transaction as anything
 * typed by hand. A read is a suggestion. There is no path from here to the
 * standings that does not go through a person.
 */
class ScoreSheetController extends Controller
{
    /**
     * A results table split over more than this is a table that wants retaking.
     *
     * The same cap on the per-player screen, which genuinely needs several: it shows
     * about three squad blocks at a time, so a lobby of seventeen is six screenshots
     * before anybody has been careless.
     */
    private const MAX_IMAGES = 8;

    /**
     * The Star of the Match screen is one screen. More than a few is somebody
     * uploading the wrong thing, or a match they have muddled with another.
     */
    private const MAX_AWARD_IMAGES = 3;

    public function read(Request $request, TournamentMatch $match, ScoreSheetReader $reader): JsonResponse
    {
        $data = $request->validate(
            ScoreSheetImage::rules(self::MAX_IMAGES),
            ScoreSheetImage::messages(self::MAX_IMAGES),
        );

        $match->load([
            'tournament.pointRule',
            'entrants.entrant.registration:id,team_name,reference',
        ]);

        $fields = ScoreSheetFields::forRule($match->tournament->pointRule);

        /*
         | Refused rather than quietly ignored. The button is not drawn on a fixture
         | that is not a battle royale lobby, but a POST does not need a button, and
         | the reader has only been taught one shape of table.
         */
        if ($fields === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Reading a screenshot only works on a battle royale lobby, scored on a table of finishing positions and kills. This fixture is not one.',
            ], 422);
        }

        $candidates = $this->candidatesFor($match, $fields);

        if ($candidates === []) {
            return response()->json([
                'ok' => false,
                'message' => 'This fixture has no competitors in it yet.',
            ], 422);
        }

        /*
         | What leaves this server: the image, and the names of the competitors in
         | this one fixture. The names earn their place — they are what turns a
         | half-legible row into the right team, and a team name is already on the
         | public bracket. Nothing else goes, and in particular no player, no
         | identity card number, no telephone number and no email address.
         |
         | The bytes are read here and never written. A screenshot of a scoreboard
         | is working material, not evidence; the Proof panel on the form is where
         | evidence is kept, deliberately and by somebody's choice.
         */
        $images = ScoreSheetImage::fromUploads($data['images']);

        try {
            $reading = $reader->read($images, array_column($candidates, 'name'));
            $result = (new ScoreSheetMatcher($candidates))->match($reading->rows);
        } catch (ScoreSheetException $e) {
            Log::warning('A score sheet could not be read.', [
                'match' => $match->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->publicMessage], 422);
        }

        // Rows the reader could not turn into numbers join the rows that matched
        // nothing. Both are shown; neither is filled in.
        $unmatched = array_merge($result['unmatched'], array_map(fn (array $row) => [
            'read_as' => $row['name'],
            'placement' => null,
            'kills' => null,
            'reason' => $row['reason'],
        ], $reading->rejected));

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Read a score sheet for %s: %d of %d rows matched, %d not.',
            $match->label(),
            count($result['rows']),
            count($result['rows']) + count($unmatched),
            count($unmatched),
        ));

        return response()->json([
            'ok' => true,
            'fields' => $fields->toArray(),
            'rows' => $result['rows'],
            'unmatched' => $unmatched,
            'uncovered' => $result['uncovered'],

            // The same team read differently in two of the images. Reported rather
            // than resolved: the first reading is kept and the row arrives unticked.
            'clashes' => $result['clashes'],

            'review_below' => ScoreSheetMatcher::REVIEW_BELOW,
        ]);
    }

    /**
     * Reading the game's Star of the Match screen into the award dialog.
     *
     * The same promise as the read above, and the same reason for existing: three
     * cards of four figures each, copied by hand off a screen in the other window,
     * is where the mistakes come from. It answers with what it read and who it thinks
     * each card is about; the dialog's own Save button is still the only way any of
     * it reaches a card on the website.
     */
    public function readAwards(Request $request, TournamentMatch $match, MatchAwardReader $reader): JsonResponse
    {
        $data = $request->validate(
            ScoreSheetImage::rules(self::MAX_AWARD_IMAGES),
            ScoreSheetImage::messages(self::MAX_AWARD_IMAGES),
        );

        $match->load(['tournament.pointRule', 'entrants.entrant']);

        $rule = $match->tournament->pointRule;

        /*
         | Refused rather than quietly ignored, in both cases. The control is not
         | drawn on a fixture that fails either test, but a POST does not need a
         | button, and the two refusals are told apart because they are fixed in
         | different places: one is the wrong sport, the other is an unfinished
         | scoring profile.
         */
        if ($rule?->kind !== PointRule::KIND_BATTLE_ROYALE) {
            return response()->json([
                'ok' => false,
                'message' => 'Reading a Star of the Match screen only works on a battle royale lobby. This fixture is not one.',
            ], 422);
        }

        $sheet = MatchAwardSheet::forRule($rule);

        if ($sheet === null) {
            return response()->json([
                'ok' => false,
                'message' => ScoreSheetException::noAwards()->publicMessage,
            ], 422);
        }

        $candidates = $this->playersFor($match, $rule);

        if ($candidates === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Nobody in this fixture has a line-up on file, so there is no one to give an award to.',
            ], 422);
        }

        /*
         | What leaves this server: the image, the award names and figure names off
         | this tournament's own scoring profile, and the in-game names of the players
         | in this one fixture. The in-game name is the only identity printed on that
         | screen, so it is the only thing that can turn a card into a person.
         |
         | Nothing else goes. No identity card number, no telephone number, no email
         | address, and no legal name except where a player's entry carries no in-game
         | name at all and that is all there is to spell.
         */
        $nicknames = array_values(array_unique(array_filter(array_map(
            fn (array $candidate) => $candidate['ign'] !== '' ? $candidate['ign'] : $candidate['name'],
            $candidates,
        ))));

        $definitions = $sheet->definitions();

        try {
            $reading = $reader->read(ScoreSheetImage::fromUploads($data['images']), $definitions, $nicknames);
            $result = (new MatchAwardMatcher($candidates))->match($reading->cards, $definitions);
        } catch (ScoreSheetException $e) {
            Log::warning('A Star of the Match screen could not be read.', [
                'match' => $match->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->publicMessage], 422);
        }

        // Cards the reader could not put against an award in this profile, and cards
        // that duplicated one it already had. Both are shown; neither is filled in.
        $unknown = array_merge($result['unknown'], $reading->unknown);

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Read a Star of the Match screen for %s: %d of %d awards filled in, %d card(s) left out.',
            $match->label(),
            count($result['awards']),
            count($definitions),
            count($unknown),
        ));

        return response()->json([
            'ok' => true,
            'awards' => $result['awards'],
            'uncovered' => $result['uncovered'],
            'unknown' => $unknown,
            'review_below' => ScoreSheetMatcher::REVIEW_BELOW,
        ]);
    }

    /**
     * Reading the game's own per-player result screen into the whole form.
     *
     * FOUR ANSWERS OUT OF ONE IMAGE, which is what makes this one worth the reading:
     * the block's rank is the squad's placement, its players' eliminations added up
     * are the squad's kills, each line is that player's own figure, and rank 1 took
     * the chicken dinner. Typed by hand that is a lobby of seventeen squads, five
     * players each, copied off a phone.
     *
     * The same promise as the two reads above it. Nothing is written here. It answers
     * with what it read and who it matched that to, the dialog shows every figure
     * against what is on the form now, and the result is still saved by the form's own
     * Save button.
     */
    public function readPlayers(Request $request, TournamentMatch $match, PlayerSheetReader $reader): JsonResponse
    {
        $data = $request->validate(
            ScoreSheetImage::rules(self::MAX_IMAGES),
            ScoreSheetImage::messages(self::MAX_IMAGES),
        );

        $match->load([
            'tournament.pointRule',
            'entrants.entrant.registration:id,team_name,reference',
            'entrants.players',
        ]);

        $rule = $match->tournament->pointRule;
        $fields = ScoreSheetFields::forRule($rule);

        /*
         | Three refusals, told apart because they are fixed in three different
         | places: the wrong sport, a profile that keeps no personal figures, and a
         | fixture whose entries name nobody. The control is not drawn where any of
         | them is true, but a POST does not need a button.
         */
        if ($fields === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Reading a screenshot only works on a battle royale lobby, scored on a table of finishing positions and kills. This fixture is not one.',
            ], 422);
        }

        /*
         | A profile that names a handful of players instead of listing line-ups has
         | no per-squad panels on screen, so there is nowhere for a block of five
         | players to go. Its own refusal rather than the one below, because the two
         | are fixed in different places.
         */
        if ($rule->picksPlayers()) {
            return response()->json([
                'ok' => false,
                'message' => 'This scoring profile records a few named players rather than whole line-ups, so there are no per-squad panels for a per-player screen to fill in.',
            ], 422);
        }

        $definitions = $this->playerFieldsFor($rule);

        if ($definitions === []) {
            return response()->json([
                'ok' => false,
                'message' => ScoreSheetException::noPlayerFields()->publicMessage,
            ], 422);
        }

        $entrants = $this->candidatesFor($match, $fields);
        $roster = $this->playersFor($match, $rule);

        if ($roster === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Nobody in this fixture has a line-up on file, so there is no way to tell which squad a block of players belongs to.',
            ], 422);
        }

        /*
         | What leaves this server: the image, the names of the personal figures off
         | this tournament's own scoring profile, and the in-game names of the players
         | in this one fixture. The handle is the only identity printed on that screen,
         | so it is the only thing that can turn a block into a squad.
         |
         | Nothing else goes. No identity card number, no telephone number, no email
         | address, and no legal name except where a player's entry carries no in-game
         | name at all and that is all there is to spell.
         */
        $nicknames = array_values(array_unique(array_filter(array_map(
            fn (array $player) => $player['ign'] !== '' ? $player['ign'] : $player['name'],
            $roster,
        ))));

        try {
            $reading = $reader->read(ScoreSheetImage::fromUploads($data['images']), $definitions, $nicknames);

            // The recorded figure rides along so the dialog can show a change as a
            // change. Which figure that is only becomes known once the reader has said
            // which field the elimination column belongs to.
            $players = $this->withRecorded($match, $roster, $reading->field);

            $result = (new PlayerSheetMatcher($entrants, $players))->match($reading->blocks);
        } catch (ScoreSheetException $e) {
            Log::warning('A per-player result screen could not be read.', [
                'match' => $match->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->publicMessage], 422);
        }

        // Blocks the reader could not turn into numbers join the blocks that matched
        // no squad. Both are shown; neither is filled in.
        $unmatched = array_merge($result['unmatched'], array_map(fn (array $block) => [
            'read_as' => $block['read_as'],
            'placement' => null,
            'kills' => null,
            'reason' => $block['reason'],
        ], $reading->rejected));

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Read a per-player result screen for %s: %d of %d blocks matched, %d not.',
            $match->label(),
            count($result['teams']),
            count($result['teams']) + count($unmatched),
            count($unmatched),
        ));

        return response()->json([
            'ok' => true,
            'fields' => $fields->toArray(),

            // The profile's own key for the elimination column, answered by the reader
            // against the labels it was given. Nothing here or in the browser knows a
            // personal figure is called Elims.
            'player_field' => $this->playerField($definitions, $reading->field),

            'teams' => $result['teams'],
            'unmatched' => $unmatched,
            'uncovered' => $result['uncovered'],
            'clashes' => $result['clashes'],
            'review_below' => ScoreSheetMatcher::REVIEW_BELOW,
        ]);
    }

    /**
     * Everybody a card could be about, with the squad they played for.
     *
     * From the same roster the dialog's dropdown is built from, so the reader cannot
     * propose a player the dropdown does not offer.
     *
     * @return array<int, array{id: int, entrant_id: int, name: string, ign: string}>
     */
    private function playersFor(TournamentMatch $match, PointRule $rule): array
    {
        $candidates = [];

        foreach (MatchRoster::forMatch($match, $rule) as $entrantId => $roster) {
            foreach ($roster as $person) {
                $candidates[] = [
                    'id' => (int) $person->id,
                    'entrant_id' => (int) $entrantId,
                    'name' => (string) $person->full_name,
                    'ign' => trim((string) $person->ign_name),
                ];
            }
        }

        return $candidates;
    }

    /**
     * The personal figures this profile keeps, as the reader is told about them.
     *
     * Keys and labels only. The reader is matching a column on a screen against the
     * names an operator typed, and the minimum and the step are the form's business.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function playerFieldsFor(?PointRule $rule): array
    {
        if ($rule === null || ! $rule->tracksPlayers()) {
            return [];
        }

        return collect($rule->player_inputs ?? [])
            ->filter(fn ($input) => is_array($input) && ($input['key'] ?? '') !== '')
            ->map(fn (array $input) => [
                'key' => (string) $input['key'],
                'label' => (string) ($input['label'] ?? $input['key']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $definitions
     * @return array{key: string, label: string}
     */
    private function playerField(array $definitions, string $key): array
    {
        foreach ($definitions as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return ['key' => $key, 'label' => $key];
    }

    /**
     * The roster, each person carrying what is already recorded for them in this
     * fixture for the one field the screen fills in.
     *
     * @param  array<int, array{id: int, entrant_id: int, name: string, ign: string}>  $roster
     * @return array<int, array{id: int, entrant_id: int, name: string, ign: string, current: int|float|null}>
     */
    private function withRecorded(TournamentMatch $match, array $roster, string $field): array
    {
        $recorded = [];

        foreach ($match->entrants as $line) {
            foreach ($line->players as $row) {
                $recorded[(int) $row->event_participant_id] = $field === ''
                    ? null
                    : $row->input($field);
            }
        }

        return array_map(
            fn (array $player) => $player + ['current' => $recorded[$player['id']] ?? null],
            $roster,
        );
    }

    /**
     * The competitors a row may be matched to, with what is already on their line.
     *
     * The recorded figures ride along so the review panel can show a change as a
     * change rather than as a number on its own.
     *
     * @return array<int, array{id: int, name: string, current: array<string, mixed>}>
     */
    private function candidatesFor(TournamentMatch $match, ScoreSheetFields $fields): array
    {
        $candidates = [];

        foreach ($match->entrants as $line) {
            if ($line->tournament_entrant_id === null || $line->entrant === null) {
                continue;
            }

            $candidates[] = [
                'id' => (int) $line->tournament_entrant_id,
                'name' => $line->entrant->displayName(),
                'current' => [
                    'placement' => $line->input($fields->placement['key']),
                    'kills' => $line->input($fields->kills['key']),
                    'winner' => $fields->winner === null
                        ? null
                        : (int) $line->input($fields->winner['key']) === 1,
                ],
            ];
        }

        return $candidates;
    }
}
