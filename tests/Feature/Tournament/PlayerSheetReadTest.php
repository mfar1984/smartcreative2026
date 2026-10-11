<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\EventParticipant;
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
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading the game's own per-player result screen.
 *
 * NOTHING HERE TOUCHES THE NETWORK. The Gemini answers are replayed through the HTTP
 * fake, shaped like the screen the owner checked this against: blocks of players with
 * clan prefixes that disagree with each other, handles carrying CJK and ornaments,
 * and the panel for the top ranks repeated on every screenshot.
 *
 * THE GUARD RAILS THESE EXIST FOR, and there are four.
 *
 * A squad is identified by its PLAYERS. That screen prints no team names at all, and
 * the prefixes are not dependable — one squad prints D2W and another D2WGx — so a
 * block that cannot be tied to exactly one entry is handed back untouched.
 *
 * The figure lands on whichever personal field the PROFILE declares. The labels on
 * that profile are editable on screen and the owner's live one declares nine of
 * them, so nothing in this feature may hold "Elims" as a constant.
 *
 * WHO PLAYED IS NEVER DECIDED HERE. A squad with five on its entry and four on the
 * screen is a question for a person, because a player who turned up and got nothing
 * is not the player who did not turn up.
 *
 * And a read writes nothing, which is the promise all three readers make.
 */
class PlayerSheetReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-flash-latest']);
    }

    /* ---------------------------------------------------------------------
     | Four answers off one screen
     * ------------------------------------------------------------------ */

    public function test_one_block_gives_a_placement_a_kill_total_and_every_players_own_figure(): void
    {
        [$match, $people, $entrants] = $this->lobby();

        $this->fakeGemini([
            $this->block('1', [['FrezRoz男生', '4'], ['『DV』INUII', '3']]),
            $this->block('3', [['K3¬ZYΔD', '1'], ['K3¬DING', '5'], ['K3¬Damz', '1'], ['K3¬JON', '1']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        // The profile's own keys, for the team figures and for the personal one.
        $response->assertJsonPath('fields.placement.key', 'placement');
        $response->assertJsonPath('fields.kills.key', 'kills');
        $response->assertJsonPath('fields.winner.key', 'wwcd');
        $response->assertJsonPath('player_field.key', 'elims');

        $teams = collect($response->json('teams'))->keyBy('name');

        // The rank off the block.
        $this->assertSame(1, $teams['MDESA']['placement']);
        $this->assertSame(3, $teams['K3']['placement']);

        // And the kill count, which is nowhere on that screen as a figure: it is the
        // block's player eliminations added up.
        $this->assertSame(7, $teams['MDESA']['kills']);
        $this->assertSame(8, $teams['K3']['kills']);

        // Rank 1 takes the chicken dinner, and only rank 1.
        $this->assertTrue($teams['MDESA']['winner']);
        $this->assertFalse($teams['K3']['winner']);

        // Every player line, matched to the person it is about, with their own figure.
        $players = collect($teams['K3']['players'])->keyBy('read_as');

        $this->assertSame($people['K3¬DING'], $players['K3¬DING']['id']);
        $this->assertSame(5, $players['K3¬DING']['value']);
        $this->assertSame(1, $players['K3¬JON']['value']);

        // The handles carry CJK and ornaments, and they still reach a person.
        $this->assertSame(
            $people['FrezRoz男生'],
            collect($teams['MDESA']['players'])->firstWhere('read_as', 'FrezRoz男生')['id'],
        );
        $this->assertSame(
            $people['『DV』INUII'],
            collect($teams['MDESA']['players'])->firstWhere('read_as', '『DV』INUII')['id'],
        );

        // Read cleanly, so the rows arrive ticked rather than flagged.
        $this->assertFalse($teams['K3']['low_confidence']);
        $this->assertSame($entrants['K3'], $teams['K3']['entrant_id']);
    }

    public function test_a_squad_is_found_through_its_players_and_not_through_a_clan_prefix(): void
    {
        [$match, $people, $entrants] = $this->lobby();

        /*
         | The trap this matching exists for. One squad's own members print two
         | different prefixes — D2W and D2WGx — so a reader that grouped by prefix
         | would make two squads out of one. The entry list is what settles it.
         */
        $this->fakeGemini([
            $this->block('5', [['D2W丶Gx', '2'], ['D2WGximie丶影', '6'], ['Z1✕LARA', '0']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('teams.0.entrant_id', $entrants['D2W']);
        $response->assertJsonPath('teams.0.placement', 5);
        $response->assertJsonPath('teams.0.kills', 8);

        $this->assertSame(
            $people['Z1✕LARA'],
            collect($response->json('teams.0.players'))->firstWhere('read_as', 'Z1✕LARA')['id'],
        );
    }

    public function test_a_block_whose_players_span_two_squads_is_reported_rather_than_assigned(): void
    {
        [$match] = $this->lobby();

        // Two people who play for different entries in one block. Either a handle was
        // misread or the wrong fixture is open; both want a person, not an average.
        $this->fakeGemini([
            $this->block('2', [['K3¬DING', '4'], ['FrezRoz男生', '2']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();

        // Nothing filled in, and the block handed back with what was printed in it.
        $this->assertSame([], $response->json('teams'));
        $this->assertStringContainsString('K3¬DING 4', $response->json('unmatched.0.read_as'));
        $this->assertStringContainsString('two different entries', $response->json('unmatched.0.reason'));
    }

    public function test_a_block_matching_nobody_is_reported_rather_than_given_to_the_nearest(): void
    {
        [$match] = $this->lobby();

        $this->fakeGemini([
            $this->block('4', [['SomeoneElseEntirely', '3'], ['AnotherStranger', '1']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();
        $this->assertSame([], $response->json('teams'));
        $this->assertStringContainsString('none of the players', $response->json('unmatched.0.reason'));
    }

    /* ---------------------------------------------------------------------
     | Several images of one screen
     * ------------------------------------------------------------------ */

    public function test_the_panel_repeated_on_every_screenshot_is_read_once(): void
    {
        [$match] = $this->lobby();

        /*
         | That screen shows about three blocks at a time and a fixed panel for the top
         | ranks is printed on EVERY screenshot, so six images of a lobby of seventeen
         | carry rank 1 six times. A duplicate that agrees is dropped without a word.
         */
        $this->fakeGemini([
            $this->block('1', [['FrezRoz男生', '4'], ['『DV』INUII', '3']]),
            $this->block('3', [['K3¬DING', '5'], ['K3¬JON', '1']]),
            $this->block('1', [['FrezRoz男生', '4'], ['『DV』INUII', '3']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [
                    UploadedFile::fake()->image('one.png'),
                    UploadedFile::fake()->image('two.png'),
                ],
            ]);

        $response->assertOk();

        $this->assertCount(2, $response->json('teams'));
        $this->assertSame([], $response->json('clashes'));

        // Once, with its figures intact rather than doubled.
        $mdesa = collect($response->json('teams'))->firstWhere('name', 'MDESA');

        $this->assertSame(7, $mdesa['kills']);
        $this->assertFalse($mdesa['conflict']);
    }

    public function test_a_squad_read_two_different_ways_is_reported_as_a_clash(): void
    {
        [$match] = $this->lobby();

        // The same squad on two images with different figures. The first reading
        // stands, the disagreement is reported, and the row arrives flagged.
        $this->fakeGemini([
            $this->block('3', [['K3¬DING', '5'], ['K3¬JON', '1']]),
            $this->block('3', [['K3¬DING', '2'], ['K3¬JON', '1']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [
                    UploadedFile::fake()->image('one.png'),
                    UploadedFile::fake()->image('two.png'),
                ],
            ]);

        $response->assertOk();

        $this->assertCount(1, $response->json('teams'));
        $this->assertTrue($response->json('teams.0.conflict'));
        $this->assertSame(6, $response->json('teams.0.kills'));

        $this->assertSame('K3', $response->json('clashes.0.name'));
        $this->assertSame(3, $response->json('clashes.0.also_read.placement'));
        $this->assertSame(3, $response->json('clashes.0.also_read.kills'));
    }

    public function test_two_squads_on_one_placement_refuses_the_whole_read(): void
    {
        [$match] = $this->lobby();

        $this->fakeGemini([
            $this->block('2', [['K3¬DING', '5']]),
            $this->block('2', [['FrezRoz男生', '4']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        // The whole read, not the second block: a screen with two seconds on it was
        // misread somewhere, and which of the two is wrong is not a guess to make.
        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString('misread', $response->json('message'));
    }

    /* ---------------------------------------------------------------------
     | The field the figure lands on
     * ------------------------------------------------------------------ */

    public function test_the_elimination_figure_lands_on_whichever_field_the_profile_declares(): void
    {
        /*
         | The same screen against a profile where the personal figures were renamed
         | into Malay by an operator. Nothing in this feature may hold "elims" as a
         | constant: the labels are editable on screen, and this is the test that
         | breaks if somebody keys off one.
         */
        [$match] = $this->lobby([
            ['key' => 'bunuh', 'label' => 'Bunuh', 'type' => 'integer', 'min' => 0, 'required' => false],
            ['key' => 'kerosakan', 'label' => 'Kerosakan', 'type' => 'integer', 'min' => 0, 'required' => false],
        ]);

        $this->fakeGemini([
            $this->block('3', [['K3¬DING', '5'], ['K3¬JON', '1']]),
        ], 'bunuh');

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('player_field.key', 'bunuh');
        $response->assertJsonPath('player_field.label', 'Bunuh');
        $response->assertJsonPath('teams.0.kills', 6);

        // And the reader was told those names rather than ours, which is the only way
        // it could have answered with one of them.
        Http::assertSent(function (ClientRequest $request) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            $this->assertStringContainsString('key "bunuh", named "Bunuh"', $prompt);
            $this->assertStringContainsString('key "kerosakan", named "Kerosakan"', $prompt);
            $this->assertStringNotContainsString('elims', $prompt);

            // The handles, which are the only thing that can turn a block into a
            // squad, and nothing else about the people behind them.
            $this->assertStringContainsString('K3¬DING', $prompt);
            $this->assertStringNotContainsString('951010100', $prompt);
            $this->assertStringNotContainsString('0123456789', $prompt);
            $this->assertStringNotContainsString('@example.com', $prompt);

            // The key goes in the header, never on the URL.
            $this->assertSame('test-key', $request->header('X-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString('test-key', $request->url());

            return true;
        });
    }

    public function test_a_field_this_profile_does_not_declare_is_refused(): void
    {
        [$match] = $this->lobby();

        // A key we never offered. Refused rather than dropped onto the first field:
        // eliminations in the Damage column is an invented statistic.
        $this->fakeGemini([$this->block('3', [['K3¬DING', '5']])], 'something_else');

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('elimination', $response->json('message'));
    }

    public function test_the_wrong_screenshot_is_answered_with_nothing_rather_than_a_profile_complaint(): void
    {
        [$match] = $this->lobby();

        // Told to return no blocks rather than invent them. Nothing read is the wrong
        // image, which is not the same as a profile with no elimination field on it.
        $this->fakeGemini([], '');

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('cat.png')],
            ]);

        $response->assertOk();
        $this->assertSame([], $response->json('teams'));
        $this->assertSame([], $response->json('unmatched'));
    }

    /* ---------------------------------------------------------------------
     | Who played is not the screen's business
     * ------------------------------------------------------------------ */

    public function test_players_no_block_mentions_are_listed_and_their_played_state_left_alone(): void
    {
        [$match, $people] = $this->lobby();

        // Three of the four on K3's entry. The fourth did not play, or was cropped
        // out of the screenshot, and this feature does not know which.
        $this->fakeGemini([
            $this->block('3', [['K3¬ZYΔD', '1'], ['K3¬DING', '5'], ['K3¬Damz', '1']]),
        ]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertOk();

        $absent = collect($response->json('teams.0.absent'));

        $this->assertCount(1, $absent);
        $this->assertSame($people['K3¬JON'], $absent->first()['id']);

        // And nothing in the answer proposes a Played state for anybody.
        $this->assertStringNotContainsString('took_part', $response->content());
    }

    public function test_a_read_writes_nothing_and_never_changes_who_played(): void
    {
        [$match, $people, $entrants] = $this->lobby();

        // Somebody already marked as not having played, with a figure on file.
        $line = $match->entrants()->where('tournament_entrant_id', $entrants['K3'])->sole();

        $row = $line->players()->create([
            'event_participant_id' => $people['K3¬JON'],
            'took_part' => false,
            'inputs' => ['elims' => 2],
        ]);

        $this->fakeGemini([
            $this->block('3', [['K3¬ZYΔD', '1'], ['K3¬DING', '5'], ['K3¬JON', '7']]),
        ]);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ])
            ->assertOk();

        $row->refresh();
        $match->refresh();

        // Not the played flag, not the figure, not a point, not a status, and no
        // proof kept of the image.
        $this->assertFalse($row->took_part);
        $this->assertSame(2, $row->input('elims'));
        $this->assertNull($match->scored_at);
        $this->assertSame(TournamentMatch::STATUS_SCHEDULED, $match->status);
        $this->assertSame(0, $match->proofs()->count());

        foreach ($match->entrants as $entrantLine) {
            $this->assertFalse($entrantLine->isScored(), 'A read wrote a figure onto a line.');
            $this->assertSame(0.0, (float) $entrantLine->points);
        }
    }

    /* ---------------------------------------------------------------------
     | The gates
     * ------------------------------------------------------------------ */

    public function test_the_endpoint_is_refused_without_the_scoring_permission(): void
    {
        [$match] = $this->lobby();
        $this->fakeGemini([$this->block('1', [['K3¬DING', '5']])]);

        $this->actingAs($this->userWith(['tournaments.view', 'tournaments.matches.view']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_bracket_fixture_is_refused_and_never_reaches_the_reader(): void
    {
        $this->fakeGemini([$this->block('1', [['Anybody', '1']])]);

        $match = $this->bracketFixture();

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        // Refused, not quietly ignored: a POST does not need a button to exist.
        $response->assertStatus(422);
        $this->assertStringContainsString('battle royale', $response->json('message'));

        Http::assertNothingSent();
    }

    public function test_a_profile_that_names_a_few_players_is_refused(): void
    {
        [$match] = $this->lobby();

        // Slot mode: the operator names the best few out of the whole fixture, so
        // there are no per-squad panels on screen and nowhere for a block to go.
        $match->tournament->pointRule->update(['player_slots' => 8]);

        $this->fakeGemini([$this->block('1', [['K3¬DING', '5']])]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match->fresh()), [
                'images' => [UploadedFile::fake()->image('result.png')],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('named players', $response->json('message'));

        Http::assertNothingSent();
    }

    public function test_more_than_eight_images_is_refused(): void
    {
        Http::fake();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-players', $match), [
                'images' => array_map(
                    fn (int $index) => UploadedFile::fake()->image('result-' . $index . '.png'),
                    range(1, 9),
                ),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        Http::assertNothingSent();
    }

    /* ---------------------------------------------------------------------
     | The screen itself
     * ------------------------------------------------------------------ */

    public function test_the_control_is_offered_on_a_lobby_that_keeps_personal_figures(): void
    {
        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSee('Detect Image AI (Per Player)')

            // The panel it opens, the endpoint it posts to, and the helpers all three
            // readers share. A view:cache would compile this page whether or not the
            // directives in it can run, so the one thing worth asserting is that it
            // RENDERS.
            ->assertSee('player-sheet-dialog')
            ->assertSee(route('admin.tournaments.matches.score.read-players', $match), false)
            ->assertSee('window.scoreReview', false);
    }

    public function test_the_player_row_names_the_handle_the_game_prints(): void
    {
        [$match] = $this->lobby();

        /*
         | The numeric account id appears on NO game screen. An operator checking what
         | the reader matched, or typing a figure off a result list, has only the
         | nickname to go by, so the row leads with it.
         */
        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSee('FrezRoz男生 (51446249301)', false);
    }

    public function test_a_player_with_no_handle_falls_back_to_the_account_id(): void
    {
        [$match] = $this->lobby();

        EventParticipant::where('ign_name', 'K3¬JON')->update(['ign_name' => null]);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSee('IGN 51446249306');
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /**
     * One block, in the shape the reader asks for.
     *
     * @param  array<int, array{0: string, 1: string}>  $players
     * @return array<string, mixed>
     */
    private function block(string $rank, array $players): array
    {
        return [
            'rank' => $rank,
            'players' => array_map(
                fn (array $player) => ['name' => $player[0], 'eliminations' => $player[1]],
                $players,
            ),
        ];
    }

    /**
     * The recorded shape, with blocks of this test's choosing inside it.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function fakeGemini(array $blocks, string $field = 'elims'): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => json_encode(['field' => $field, 'blocks' => $blocks])]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    /**
     * A battle royale lobby that keeps personal figures, with line-ups on every entry.
     *
     * The handles are the owner's real ones: CJK, ornaments, and two members of one
     * squad printing two different clan prefixes.
     *
     * @param  array<int, array<string, mixed>>|null  $playerInputs  null for the nine the
     *                                                               owner's live profile declares
     * @return array{0: TournamentMatch, 1: array<string, int>, 2: array<string, int>}
     */
    private function lobby(?array $playerInputs = null): array
    {
        $event = Event::create([
            'slug' => 'esport-' . uniqid(),
            'title' => 'PUBG Player Sheet Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'seats_total' => 100,
            'min_players' => 1,
        ]);

        $rule = PointRule::create([
            'name' => 'Battle royale ' . uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
            'track_players' => PointRule::TRACK_OPTIONAL,
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

            'player_components' => [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'per_unit', 'source' => 'elims', 'value' => 1],
            ],

            // The nine the owner's live profile declares, of which exactly one is on
            // this screen. The other eight are left blank: no screen provides them.
            'player_inputs' => $playerInputs ?? [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'knock_outs', 'label' => 'Knock Outs', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'assists', 'label' => 'Assists', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'rescue', 'label' => 'Rescue', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'damage', 'label' => 'Damage', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'health_restored', 'label' => 'Health Restored', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'survival_time', 'label' => 'Survival Time', 'type' => 'integer', 'min' => 0, 'required' => false],
                ['key' => 'travel_distance', 'label' => 'Travel Distance', 'type' => 'integer', 'min' => 0, 'required' => false],
            ],
            'player_tiebreak' => ['elims'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Player Sheet Tournament',
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

        // In-game names as the entry list spells them, which is what the blocks are
        // matched against. The private columns are filled in too, because half of what
        // is checked here is that they never leave the server.
        $squads = [
            'MDESA' => ['FrezRoz男生', '『DV』INUII'],
            'K3' => ['K3¬ZYΔD', 'K3¬DING', 'K3¬Damz', 'K3¬JON'],
            'D2W' => ['D2W丶Gx', 'D2WGximie丶影', 'Z1✕LARA'],
        ];

        $people = [];
        $entrants = [];
        $slot = 0;
        $account = 51446249300;

        foreach ($squads as $team => $handles) {
            $slot++;

            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => EventRegistration::nextReference(),
                'mode' => Event::MODE_MANAGER,
                'team_name' => $team,
                'status' => EventRegistration::STATUS_CONFIRMED,
                'payment_status' => EventRegistration::PAYMENT_PAID,
                'amount' => 0,
            ]);

            foreach ($handles as $index => $handle) {
                $account++;

                $people[$handle] = (int) EventParticipant::create([
                    'event_registration_id' => $registration->id,
                    'role' => ParticipantOptions::ROLE_PLAYER,
                    'full_name' => $team . ' Player ' . ($index + 1),
                    'ign_name' => $handle,
                    'ign_player_id' => (string) $account,
                    'ic_number' => '95101010' . $slot . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'phone' => '0123456789',
                    'email' => 'player' . $slot . $index . '@example.com',
                ])->id;
            }

            $entrant = TournamentEntrant::create([
                'tournament_id' => $tournament->id,
                'event_registration_id' => $registration->id,
                'seed' => $slot,
                'status' => TournamentEntrant::STATUS_ACTIVE,
            ]);

            TournamentMatchEntrant::create([
                'tournament_match_id' => $match->id,
                'tournament_entrant_id' => $entrant->id,
                'slot' => $slot,
            ]);

            $entrants[$team] = (int) $entrant->id;
        }

        return [$match->fresh(), $people, $entrants];
    }

    /** A knockout fixture, which is every sport this feature is not for. */
    private function bracketFixture(): TournamentMatch
    {
        $event = Event::create([
            'slug' => 'ml-' . uniqid(),
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
            'name' => 'Bracket ' . uniqid(),
            'kind' => PointRule::KIND_BRACKET,
            'squad_size' => 5,
            'track_players' => PointRule::TRACK_OPTIONAL,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'per_unit', 'source' => 'series_won', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'integer', 'min' => 0, 'max' => 3, 'required' => true],
            ],
            'tiebreak' => ['series_won'],

            // Personal figures configured and still refused, because the gate is the
            // kind of fixture: this reader has only been taught one game's screen.
            'player_components' => [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'per_unit', 'source' => 'elims', 'value' => 1],
            ],
            'player_inputs' => [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'integer', 'min' => 0, 'required' => false],
            ],
            'player_tiebreak' => ['elims'],
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
     * A real role rather than the super-admin shortcut: part of what is checked here
     * is that the endpoint is unreachable without its permission, and super admin
     * short-circuits hasPermission() to true.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
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
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
