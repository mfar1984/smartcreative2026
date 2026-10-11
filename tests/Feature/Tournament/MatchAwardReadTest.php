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
 * Reading the game's Star of the Match screen into the award dialog.
 *
 * NOTHING HERE TOUCHES THE NETWORK. The Gemini answer is a recorded one, replayed
 * through the HTTP fake, taken from the screen the owner checked this against: three
 * cards, each with a different set of stats, figures printed with thousand
 * separators, and a nickname that OCR spells a character differently from the entry
 * list.
 *
 * THE GUARD RAILS THESE EXIST FOR. The endpoint may never write an award. And
 * nothing in this feature may know what an award is called: every label on that
 * screen came out of a scoring profile somebody typed, so a profile renamed into
 * Malay has to work the same day it is saved.
 */
class MatchAwardReadTest extends TestCase
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

    public function test_the_recorded_screen_fills_the_cards_the_profile_hands_out(): void
    {
        $this->fakeGemini();

        [$match, $people] = $this->lobby();

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $awards = collect($response->json('awards'))->keyBy('key');

        // Every card on that screen shows a different set of stats, and each one is
        // mapped onto the fields its own award was configured with.
        $this->assertSame([
            'knock_outs' => 0,
            'elims' => 7,
            'bonus' => 4,
            'damage' => 942,
        ], $awards['going_all_out']['figures']);

        $this->assertSame([
            'assists' => 1,
            'rescue' => 2,
            'health_restored' => 127,
            'survival_time' => 434,
        ], $awards['best_companion']['figures']);

        /*
         | The separator trap, and the one worth being blunt about. These figures are
         | printed grouped: 9,617 metres travelled and 1,321 seconds survived. Read as
         | decimals they would put nine metres and one second on the card, which is a
         | figure that looks plausible and is wrong by a factor of a thousand.
         */
        $this->assertSame(9617, $awards['finisher']['figures']['travel_distance']);
        $this->assertSame(1321, $awards['finisher']['figures']['survival_time']);

        // The player each card is about, found by the only identity on that screen.
        $this->assertSame($people['LxRAAnZR'], $awards['best_companion']['player']['id']);
        $this->assertSame('Low Rui An', $awards['best_companion']['player']['name']);
        $this->assertSame($people['PRXiKobeBrynKR'], $awards['finisher']['player']['id']);

        /*
         | And the OCR trap. The owner's two screenshots of one player read QXCikaYELL
         | and QXCikoYELL. It is matched, because a character in ten is not a different
         | person — and it is flagged, so the row arrives unticked and somebody looks
         | at it before it goes on a card.
         */
        $this->assertSame($people['QXCikaYELL'], $awards['going_all_out']['player']['id']);
        $this->assertTrue($awards['going_all_out']['low_confidence']);
        $this->assertSame('QXCikoYELL', $awards['going_all_out']['read_as']);

        // A card read cleanly is not flagged, or nothing would ever be ticked.
        $this->assertFalse($awards['best_companion']['low_confidence']);
    }

    public function test_a_renamed_award_is_read_off_the_profile_and_not_out_of_this_code(): void
    {
        /*
         | The same screen, against a profile where every award and every figure was
         | renamed by an operator. Nothing in this feature may hold "Going All Out" as
         | a constant: the seeded profiles are examples, the labels are editable on
         | screen, and this is the test that breaks if somebody keys off one.
         */
        [$match] = $this->lobby([
            [
                'key' => 'paling_agresif',
                'label' => 'Paling Agresif',
                'headline' => 'bunuh',
                'fields' => [
                    ['key' => 'bunuh', 'label' => 'Bunuh', 'decimal' => false],
                    ['key' => 'kerosakan', 'label' => 'Kerosakan', 'decimal' => false],
                ],
            ],
        ]);

        $this->fakeGeminiWith([[
            'award' => 'paling_agresif',
            'label' => 'Paling Agresif',
            'player' => 'LxRAAnZR',
            'figures' => [
                ['field' => 'bunuh', 'value' => '7'],
                ['field' => 'kerosakan', 'value' => '1,942'],
            ],
        ]]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertOk();
        $response->assertJsonPath('awards.0.key', 'paling_agresif');
        $response->assertJsonPath('awards.0.label', 'Paling Agresif');
        $response->assertJsonPath('awards.0.figures.bunuh', 7);
        $response->assertJsonPath('awards.0.figures.kerosakan', 1942);

        // And the reader was told those names rather than ours, which is the only way
        // it could have put a card against them.
        Http::assertSent(function (ClientRequest $request) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            $this->assertStringContainsString('Paling Agresif', $prompt);
            $this->assertStringContainsString('kerosakan = "Kerosakan"', $prompt);
            $this->assertStringNotContainsString('Going All Out', $prompt);

            return true;
        });
    }

    public function test_a_card_for_an_award_the_profile_does_not_hand_out_is_reported(): void
    {
        [$match] = $this->lobby([
            [
                'key' => 'paling_agresif',
                'label' => 'Paling Agresif',
                'headline' => 'bunuh',
                'fields' => [['key' => 'bunuh', 'label' => 'Bunuh', 'decimal' => false]],
            ],
        ]);

        // The screen showed an award this profile knows nothing about.
        $this->fakeGeminiWith([[
            'award' => 'going_all_out',
            'label' => 'Going All Out',
            'player' => 'LxRAAnZR',
            'figures' => [['field' => 'elims', 'value' => '7']],
        ]]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertOk();

        // Reported, not forced onto the nearest award. A profile missing an award is
        // something to go and fix, not something to guess around.
        $this->assertSame([], $response->json('awards'));
        $this->assertSame('Going All Out', $response->json('unknown.0.label'));
        $this->assertSame('Paling Agresif', $response->json('uncovered.0.label'));
    }

    public function test_a_figure_that_is_not_a_number_is_reported_rather_than_invented(): void
    {
        [$match] = $this->lobby();

        /*
         | The subtitle trap, from the side the parser can be held to. On the real
         | screen two cards carry a subtitle of "Elims 7" and "Rescue 2" and the third
         | carries "Final Elimination", which is words. Whatever reaches the figures,
         | a value that is not a number is never turned into one: the field is left
         | blank and named, so the operator fills it in from the image.
         */
        $this->fakeGeminiWith([[
            'award' => 'finisher',
            'label' => 'Finisher',
            'player' => 'PRXiKobeBrynKR',
            'figures' => [
                ['field' => 'elims', 'value' => 'Final Elimination'],
                ['field' => 'assists', 'value' => '3'],
            ],
        ]]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertOk();
        $this->assertSame(['assists' => 3], $response->json('awards.0.figures'));
        $this->assertSame(['elims'], $response->json('awards.0.unreadable'));
    }

    public function test_the_reader_is_told_to_read_the_stat_rows_and_is_told_nothing_private(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            // The subtitle is sometimes a figure and sometimes words, so it is ruled
            // out in the prompt rather than relied on and patched up afterwards.
            $this->assertStringContainsString('subtitle', $prompt);
            $this->assertStringContainsString('stat rows', $prompt);

            // The awards and their figures, off this tournament's own profile.
            $this->assertStringContainsString('Best Companion', $prompt);
            $this->assertStringContainsString('health_restored = "Health Restored"', $prompt);

            // The in-game names, which are the only thing that can turn a card into
            // a person, and nothing else about that person.
            $this->assertStringContainsString('QXCikaYELL', $prompt);
            $this->assertStringNotContainsString('95101010001', $prompt);
            $this->assertStringNotContainsString('0123456789', $prompt);
            $this->assertStringNotContainsString('aiman@example.com', $prompt);

            // Asked for as JSON against a schema, with the key in the header, which is
            // the one place a key belongs.
            $this->assertSame('application/json', data_get($request->data(), 'generationConfig.responseMimeType'));
            $this->assertSame('test-key', $request->header('X-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString('test-key', $request->url());

            return true;
        });
    }

    public function test_a_nickname_matching_nobody_comes_back_unmatched(): void
    {
        [$match] = $this->lobby();

        $this->fakeGeminiWith([[
            'award' => 'finisher',
            'label' => 'Finisher',
            'player' => 'Zephyr Unknown Player',
            'figures' => [['field' => 'elims', 'value' => '4']],
        ]]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertOk();

        // No player, the text that was read, and a reason. The figures still come
        // through: an operator who recognises the handle picks the player and keeps
        // what the image said.
        $this->assertNull($response->json('awards.0.player'));
        $this->assertSame('Zephyr Unknown Player', $response->json('awards.0.read_as'));
        $this->assertStringContainsString('in-game name', $response->json('awards.0.reason'));
        $this->assertSame(['elims' => 4], $response->json('awards.0.figures'));
    }

    /* ---------------------------------------------------------------------
     | When it must refuse
     * ------------------------------------------------------------------ */

    public function test_the_endpoint_is_refused_without_the_scoring_permission(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.view', 'tournaments.matches.view']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_bracket_fixture_is_refused_and_never_reaches_the_reader(): void
    {
        $this->fakeGemini();

        $match = $this->bracketFixture();

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString('battle royale', $response->json('message'));

        Http::assertNothingSent();
    }

    public function test_a_profile_that_hands_out_no_awards_is_refused(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby([]);

        $response = $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ]);

        // Refused and said out loud, not a silent nothing: there is no card for a
        // figure to go on, and that is a profile somebody has not finished.
        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString('Star of the Match', $response->json('message'));

        Http::assertNothingSent();
    }

    public function test_more_than_three_images_is_refused(): void
    {
        Http::fake();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => array_map(
                    fn (int $index) => UploadedFile::fake()->image('star-' . $index . '.png'),
                    range(1, 4),
                ),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        Http::assertNothingSent();
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Http::fake();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->create('star.pdf', 20, 'application/pdf')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        Http::assertNothingSent();
    }

    /* ---------------------------------------------------------------------
     | The promise
     * ------------------------------------------------------------------ */

    public function test_a_read_never_writes_an_award(): void
    {
        $this->fakeGemini();

        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->postJson(route('admin.tournaments.matches.score.read-awards', $match), [
                'images' => [UploadedFile::fake()->image('star.png')],
            ])
            ->assertOk();

        $match->refresh();

        // Not a card, not a figure, not a status, and no copy kept of the image.
        $this->assertSame(0, $match->awards()->count());
        $this->assertNull($match->scored_at);
        $this->assertSame(TournamentMatch::STATUS_SCHEDULED, $match->status);
        $this->assertSame(0, $match->proofs()->count());

        foreach ($match->entrants as $line) {
            $this->assertFalse($line->isScored(), 'A read wrote a figure onto a line.');
            $this->assertSame(0, $line->players()->count());
        }
    }

    /* ---------------------------------------------------------------------
     | The control, and the one piece of ordering it depends on
     * ------------------------------------------------------------------ */

    public function test_the_award_dialog_offers_the_control(): void
    {
        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSee('Detect Image AI')
            ->assertSee(route('admin.tournaments.matches.score.read-awards', $match), false);
    }

    public function test_a_profile_with_no_awards_is_not_offered_the_control(): void
    {
        [$match] = $this->lobby([]);

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertDontSee(route('admin.tournaments.matches.score.read-awards', $match), false);
    }

    public function test_apply_sets_the_player_before_it_writes_the_figures(): void
    {
        /*
         | The one piece of ordering this feature cannot be correct without.
         |
         | The award dialog already fills a card in from the player ledger when a
         | player is picked. If Apply wrote the figures and then set the player, that
         | handler would run last and overwrite the image with whatever was typed
         | earlier. So Apply sets the player, lets the handler run — dispatchEvent is
         | synchronous, which is what makes this a guarantee and not a race — and
         | writes the screenshot's figures last.
         |
         | Asserted on the shipped script because that ordering is the behaviour; a
         | comment alone would not notice somebody moving two blocks around.
         */
        [$match] = $this->lobby();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->get(route('admin.tournaments.matches.score', $match))
            ->assertOk()
            ->assertSeeInOrder([
                'ORDER MATTERS HERE',
                "select.dispatchEvent(new Event('change'",
                "the screenshot's figures last",
            ], false);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** The recorded answer, as it arrived off the real endpoint. */
    private function fakeGemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                json_decode((string) file_get_contents(base_path('tests/Fixtures/gemini-match-awards.json')), true),
            ),
        ]);
    }

    /**
     * The same recorded shape, with cards of this test's choosing inside it.
     *
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function fakeGeminiWith(array $cards): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['cards' => $cards])]], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    /**
     * A battle royale lobby whose profile hands out awards, with named players on
     * every entry.
     *
     * @param  array<int, array<string, mixed>>|null  $awards  null for the three off the
     *                                                         screen this was built against
     * @return array{0: TournamentMatch, 1: array<string, int>}  the fixture, and the
     *                                                           participant id behind each
     *                                                           in-game name
     */
    private function lobby(?array $awards = null): array
    {
        $event = Event::create([
            'slug' => 'esport-' . uniqid(),
            'title' => 'PUBG Award Test',
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
            ],
            'inputs' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'integer', 'min' => 1, 'required' => true, 'unique_in_match' => true],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                ['key' => 'players_present', 'label' => 'Players', 'type' => 'integer', 'min' => 0, 'max_from' => 'squad_size', 'required' => true],
            ],
            'tiebreak' => ['kills'],

            // The player ledger, so the award dialog's own auto-fill has somewhere to
            // read from. Deliberately smaller than the award cards: an award figure
            // with no personal stat behind it is the normal case.
            'player_components' => [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'per_unit', 'source' => 'elims', 'value' => 1],
            ],
            'player_inputs' => [
                ['key' => 'elims', 'label' => 'Elims', 'type' => 'integer', 'min' => 0, 'required' => false],
            ],
            'player_tiebreak' => ['elims'],

            'match_awards' => $awards ?? $this->awardsOffTheScreen(),
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Award Tournament',
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

        // In-game names as the entry list spells them, which is what the cards are
        // matched against. The private columns are filled in too, because half of
        // what is checked here is that they never leave the server.
        $squads = [
            'MDESA' => [
                ['QXCikaYELL', 'Aiman Bin Yusof', 'aiman@example.com'],
                ['LxRAAnZR', 'Low Rui An', 'ruian@example.com'],
            ],
            '96K' => [
                ['PRXiKobeBrynKR', 'Bryan Koh', 'bryan@example.com'],
                ['MDesaSniper', 'Danial Hakim', 'danial@example.com'],
            ],
        ];

        $people = [];
        $slot = 0;

        foreach ($squads as $team => $players) {
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

            foreach ($players as $index => [$ign, $name, $email]) {
                $people[$ign] = (int) EventParticipant::create([
                    'event_registration_id' => $registration->id,
                    'role' => ParticipantOptions::ROLE_PLAYER,
                    'full_name' => $name,
                    'ign_name' => $ign,
                    'ign_player_id' => '5120988' . $slot . $index,
                    'ic_number' => '9510101000' . $slot . $index,
                    'phone' => '0123456789',
                    'email' => $email,
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
        }

        return [$match->fresh(), $people];
    }

    /**
     * The three awards off the screen this was built against, with the figures each
     * card carried. Labels and keys as an operator would have typed them in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function awardsOffTheScreen(): array
    {
        return [
            [
                'key' => 'going_all_out',
                'label' => 'Going All Out',
                'headline' => 'elims',
                'fields' => [
                    ['key' => 'knock_outs', 'label' => 'Knock Outs', 'decimal' => false],
                    ['key' => 'elims', 'label' => 'Elims', 'decimal' => false],
                    ['key' => 'bonus', 'label' => 'Bonus', 'decimal' => false],
                    ['key' => 'damage', 'label' => 'Damage', 'decimal' => false],
                ],
            ],
            [
                'key' => 'best_companion',
                'label' => 'Best Companion',
                'headline' => 'rescue',
                'fields' => [
                    ['key' => 'assists', 'label' => 'Assists', 'decimal' => false],
                    ['key' => 'rescue', 'label' => 'Rescue', 'decimal' => false],
                    ['key' => 'health_restored', 'label' => 'Health Restored', 'decimal' => false],
                    ['key' => 'survival_time', 'label' => 'Survival Time', 'decimal' => false],
                ],
            ],
            [
                'key' => 'finisher',
                'label' => 'Finisher',
                'headline' => 'elims',
                'fields' => [
                    ['key' => 'elims', 'label' => 'Elims', 'decimal' => false],
                    ['key' => 'assists', 'label' => 'Assists', 'decimal' => false],
                    ['key' => 'travel_distance', 'label' => 'Travel Distance', 'decimal' => false],
                    ['key' => 'survival_time', 'label' => 'Survival Time', 'decimal' => false],
                ],
            ],
        ];
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

            // Awards configured and still refused, because the gate is the kind of
            // fixture: this reader has only been taught one game's screen.
            'match_awards' => $this->awardsOffTheScreen(),
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
