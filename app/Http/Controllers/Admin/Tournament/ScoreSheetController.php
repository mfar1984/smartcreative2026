<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Contracts\ScoreSheetReader;
use App\Http\Controllers\Controller;
use App\Models\TournamentMatch;
use App\Services\AdminLogger;
use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetImage;
use App\Support\Tournament\ScoreSheetFields;
use App\Support\Tournament\ScoreSheetMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Reading a result off a photograph of the results table.
 *
 * Its own controller rather than another method on MatchController, which is
 * already the largest file in the module: nothing here shares a line with saving a
 * score, and that is the point of it being separate.
 *
 * THIS ENDPOINT WRITES NOTHING. It answers with what it read and what it matched
 * that to; the figures go into the form in the operator's browser and are saved by
 * the same Save button, the same validation and the same transaction as anything
 * typed by hand. A read is a suggestion. There is no path from here to the
 * standings that does not go through a person.
 */
class ScoreSheetController extends Controller
{
    /** A results table split over more than this is a table that wants retaking. */
    private const MAX_IMAGES = 8;

    public function read(Request $request, TournamentMatch $match, ScoreSheetReader $reader): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'max:' . self::MAX_IMAGES],

            // mimetypes rather than mimes: it is checked against the file's own
            // bytes, the way every other image upload in this application is.
            'images.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:4096'],
        ], [
            'images.required' => 'Pick at least one screenshot.',
            'images.max' => 'Eight screenshots at a time is the most this will read.',
            'images.*.mimetypes' => 'A screenshot must be a JPG, PNG or WebP image.',
            'images.*.max' => 'Each screenshot must be no larger than 4 MB.',
        ]);

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
        $images = array_map(
            fn (UploadedFile $file) => new ScoreSheetImage(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
            ),
            array_values($data['images']),
        );

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
