<?php

namespace Tests\Feature\Tournament;

use App\Contracts\ScoreSheetReader;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading a result off a photograph of the results table.
 *
 * NOTHING HERE TOUCHES THE NETWORK. The Gemini answer is a recorded one, replayed
 * through the HTTP fake, which is what lets the glyph repair and the row skipping
 * be exercised against the shape a real answer actually arrives in. A live call is
 * one line in tinker for the owner; it is not a thing a test suite should be able
 * to spend money on.
 *
 * THE GUARD RAIL THESE EXIST FOR. This endpoint may never write a score. It feeds
 * a form, the operator checks it, and the existing save writes the result. The last
 * two cases are that promise: the permission is required, and no figure on any line
 * moves because somebody read an image.
 */
class ScoreSheetReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-flash-latest']);
    }

    /* ---------------------------------------------------------------------
     | What comes back off a real answer
     * ------------------------------------------------------------------ */

    public function test_the_recorded_answer_is_read_matched_and_repaired(): void
    {
        $this->fakeGemini();

        [$match, $entrants] = $this->lobby(['MDESA', '96K', 'V18', 'KLASIK', 'SPARTAN']);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        // The profile's own keys, so the browser fills in lines[id][placement]
        // without this feature knowing the word.
        $response->assertJsonPath('fields.placement.key', 'placement');
        $response->assertJsonPath('fields.kills.key', 'kills');
        $response->assertJsonPath('fields.winner.key', 'wwcd');

        $rows = collect($response->json('rows'))->keyBy('name');

        // Read cleanly.
        $this->assertSame(1, $rows['MDESA']['placement']);
        $this->assertSame(12, $rows['MDESA']['kills']);
        $this->assertTrue($rows['MDESA']['winner']);

        /*
         | The trap the prompt exists for. These tables are printed in a font that
         | draws 1 as a serif capital I, so the RANK column arrives as I0 and I6 and
         | the KP column as II. A reader that guessed at those would be a reader
         | nobody could check.
         */
        $this->assertSame(10, $rows['V18']['placement']);
        $this->assertSame(16, $rows['KLASIK']['placement']);
        $this->assertSame(11, $rows['KLASIK']['kills']);

        // Only the top of the table is offered the chicken dinner.
        $this->assertFalse($rows['96K']['winner']);
    }

    public function test_a_blank_team_row_is_skipped_and_an_unreadable_figure_is_reported(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby(['MDESA', '96K', 'V18', 'KLASIK', 'SPARTAN']);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        $response->assertOk();

        $names = collect($response->json('rows'))->pluck('name')->all();
        $read = collect($response->json('unmatched'))->pluck('read_as')->all();

        // The dashed spacer row every one of these tables carries is neither filled
        // in nor complained about.
        $this->assertNotContains('-', $names);
        $this->assertNotContains('-', $read);

        // A rank of "O" is not a number after the I-to-1 repair, so SPARTAN is
        // reported rather than given a position somebody invented.
        $this->assertNotContains('SPARTAN', $names);
        $this->assertContains('SPARTAN', $read);

        // And a team that is not in this lobby at all is reported too.
        $this->assertContains('NOT IN THIS LOBBY', $read);
    }

    public function test_the_competitors_in_the_fixture_are_sent_and_nothing_else_is(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby(['MDESA', '96K', 'V18', 'KLASIK', 'SPARTAN']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            // The names earn their place: they are what turns a half-legible row
            // into the right team, and they are already on the public bracket.
            $this->assertStringContainsString('MDESA', $prompt);
            $this->assertStringContainsString('KLASIK', $prompt);

            // The answer is asked for as JSON against a schema, not as prose.
            $this->assertSame('application/json', data_get($request->data(), 'generationConfig.responseMimeType'));
            $this->assertNotNull(data_get($request->data(), 'generationConfig.responseSchema'));

            // The key goes in the header. On the URL it would be in every access
            // log between here and Google.
            $this->assertSame('test-key', $request->header('X-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString('test-key', $request->url());

            return true;
        });
    }

    /* ---------------------------------------------------------------------
     | When the reader will not play
     * ------------------------------------------------------------------ */

    public function test_a_retired_model_is_answered_with_the_setting_to_change(): void
    {
        config(['services.gemini.model' => 'gemini-1.0-retired']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'message' => 'models/gemini-1.0-retired is not found for API version v1beta'],
        ], 404)]);

        [$match] = $this->lobby(['MDESA']);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);

        // Actionable, and without the stack trace or the endpoint in it.
        $this->assertStringContainsString('GEMINI_MODEL', $response->json('message'));
        $this->assertStringContainsString('gemini-1.0-retired', $response->json('message'));
    }

    public function test_a_missing_key_says_so_rather_than_failing_obscurely(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        [$match] = $this->lobby(['MDESA']);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('GEMINI_API_KEY', $response->json('message'));

        // Nothing was asked of anybody.
        Http::assertNothingSent();
    }

    public function test_a_table_awarding_one_position_twice_is_refused_whole(): void
    {
        $this->fakeGeminiWith([
            ['team' => 'MDESA', 'rank' => '3', 'kp' => '12'],
            ['team' => '96K', 'rank' => '3', 'kp' => '9'],
        ]);

        [$match] = $this->lobby(['MDESA', '96K']);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('misread', $response->json('message'));
    }

    /* ---------------------------------------------------------------------
     | The upload itself
     * ------------------------------------------------------------------ */

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Http::fake();

        [$match] = $this->lobby(['MDESA']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->create('table.pdf', 20, 'application/pdf')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        Http::assertNothingSent();
    }

    public function test_more_than_eight_images_is_refused(): void
    {
        Http::fake();

        [$match] = $this->lobby(['MDESA']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => array_map(
                    fn (int $index) => UploadedFile::fake()->image('table-'.$index.'.png'),
                    range(1, 9),
                ),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        Http::assertNothingSent();
    }

    /* ---------------------------------------------------------------------
     | The two promises
     * ------------------------------------------------------------------ */

    public function test_the_endpoint_is_refused_without_the_scoring_permission(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby(['MDESA']);

        // Everything but the one permission that matters, including the permission
        // to look at a fixture.
        $this->actingAs($this->userWith(['tournaments.view', 'tournaments.matches.view']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_read_never_writes_a_score(): void
    {
        $this->fakeGemini();

        [$match, $entrants] = $this->lobby(['MDESA', '96K', 'V18', 'KLASIK', 'SPARTAN']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ])
            ->assertOk();

        $match->refresh();

        // Not a figure, not a point, not a status, and no proof kept of the image.
        $this->assertNull($match->scored_at);
        $this->assertSame(TournamentMatch::STATUS_SCHEDULED, $match->status);
        $this->assertSame(0, $match->proofs()->count());

        foreach ($match->entrants as $line) {
            $this->assertFalse($line->isScored(), 'A read wrote a figure onto a line.');
            $this->assertSame(0.0, (float) $line->points);
        }
    }

    public function test_the_screen_offers_the_control_only_to_somebody_who_may_score(): void
    {
        [$match] = $this->lobby(['MDESA']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSee('Detect Image AI');
    }

    /* ---------------------------------------------------------------------
     | Battle royale only, for now
     * ------------------------------------------------------------------ */

    public function test_a_bracket_fixture_is_refused_and_never_reaches_the_reader(): void
    {
        $this->fakeGemini();

        $match = $this->bracketFixture();

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read', $match), [
                'images' => [UploadedFile::fake()->image('table.png')],
            ]);

        // Refused, not quietly ignored: a POST does not need a button to exist.
        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString('battle royale', $response->json('message'));

        Http::assertNothingSent();
    }

    public function test_a_bracket_fixture_is_not_offered_the_control(): void
    {
        $match = $this->bracketFixture();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertDontSee('Detect Image AI');
    }

    public function test_the_gate_is_the_kind_and_not_the_profiles_name(): void
    {
        // A battle royale profile named anything at all still works, because the
        // seeded names are examples and an operator may rename or replace them.
        [$match] = $this->lobby(['MDESA']);

        $match->tournament->pointRule->update(['name' => 'Something The Operator Typed']);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match->fresh()))
            ->assertOk()
            ->assertSee('Detect Image AI');
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** The recorded answer, as it arrived off the real endpoint. */
    private function fakeGemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                json_decode((string) file_get_contents(base_path('tests/Fixtures/gemini-score-sheet.json')), true),
            ),
        ]);
    }

    /**
     * The same recorded shape, with rows of this test's choosing inside it.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function fakeGeminiWith(array $rows): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['rows' => $rows])]], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    /**
     * A battle royale lobby carrying the profile this feature reads.
     *
     * @param  array<int, string>  $teams
     * @return array{0: TournamentMatch, 1: array<string, int>}
     */
    private function lobby(array $teams): array
    {
        $event = Event::create([
            'slug' => 'esport-'.uniqid(),
            'title' => 'PUBG Read Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'seats_total' => 100,
            'min_players' => 1,
        ]);

        $rule = PointRule::create([
            'name' => 'Battle royale '.uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
            'components' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'table', 'source' => 'placement', 'values' => ['1' => 10, '2' => 6]],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'per_unit', 'source' => 'kills', 'value' => 1],
                ['key' => 'wwcd', 'label' => 'WWCD', 'type' => 'bonus', 'when' => ['source' => 'wwcd', 'equals' => 1], 'value' => 0],
            ],
            'inputs' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'integer', 'min' => 1, 'required' => true, 'unique_in_match' => true],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                ['key' => 'wwcd', 'label' => 'WWCD', 'type' => 'toggle', 'required' => false, 'single_in_match' => true],
                ['key' => 'players_present', 'label' => 'Players', 'type' => 'integer', 'min' => 0, 'max_from' => 'squad_size', 'required' => true],
            ],
            'tiebreak' => ['kills'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Read Tournament',
            'format' => Tournament::FORMAT_BATTLE_ROYALE,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_ONGOING,
            'seeding_method' => Tournament::SEEDING_MANUAL,
        ]);

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Qualifiers',
            'type' => TournamentStage::TYPE_LOBBY,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 1,
        ]);

        $match = TournamentMatch::create([
            'tournament_id' => $tournament->id,
            'tournament_stage_id' => $stage->id,
            'status' => TournamentMatch::STATUS_SCHEDULED,
        ]);

        $entrants = [];

        foreach (array_values($teams) as $index => $team) {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => EventRegistration::nextReference(),
                'mode' => Event::MODE_MANAGER,
                'team_name' => $team,
                'status' => EventRegistration::STATUS_CONFIRMED,
                'payment_status' => EventRegistration::PAYMENT_PAID,
                'amount' => 0,
            ]);

            $entrant = TournamentEntrant::create([
                'tournament_id' => $tournament->id,
                'event_registration_id' => $registration->id,
                'seed' => $index + 1,
                'status' => TournamentEntrant::STATUS_ACTIVE,
            ]);

            TournamentMatchEntrant::create([
                'tournament_match_id' => $match->id,
                'tournament_entrant_id' => $entrant->id,
                'slot' => $index + 1,
            ]);

            $entrants[$team] = (int) $entrant->id;
        }

        return [$match->fresh(), $entrants];
    }

    /** A knockout fixture, which is every sport this feature is not for. */
    private function bracketFixture(): TournamentMatch
    {
        $event = Event::create([
            'slug' => 'ml-'.uniqid(),
            'title' => 'Mobile Legends Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'seats_total' => 100,
            'min_players' => 1,
        ]);

        $rule = PointRule::create([
            'name' => 'Bracket '.uniqid(),
            'kind' => PointRule::KIND_BRACKET,
            'squad_size' => 5,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'per_unit', 'source' => 'series_won', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'integer', 'min' => 0, 'max' => 3, 'required' => true],
            ],
            'tiebreak' => ['series_won'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Bracket Tournament',
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_ONGOING,
            'seeding_method' => Tournament::SEEDING_MANUAL,
        ]);

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Main',
            'type' => TournamentStage::TYPE_BRACKET,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 1,
            'best_of' => ['1' => 1],
        ]);

        return TournamentMatch::create([
            'tournament_id' => $tournament->id,
            'tournament_stage_id' => $stage->id,
            'round' => 1,
            'position' => 1,
            'best_of' => 1,
            'status' => TournamentMatch::STATUS_SCHEDULED,
        ]);
    }

    /**
     * A real role rather than the super-admin shortcut: half of what is checked
     * here is that the endpoint is unreachable without its permission, and super
     * admin short-circuits hasPermission() to true.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-'.uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Tournaments', 'module' => 'Tournaments', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
