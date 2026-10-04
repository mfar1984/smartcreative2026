<?php

namespace Tests\Feature\Registration;

use App\Models\CollectionHandover;
use App\Models\CollectionVerification;
use App\Models\CollectionVerificationAttempt;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationAddon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ParticipantOptions;
use App\Support\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The counter that hands the shirts out.
 *
 * Nothing in here sends a real message. Http::fake() stands in for Infobip and
 * Http::preventStrayRequests() turns any unfaked outbound call into a failure, so a
 * change that bypasses the gateway cannot quietly start texting real people from a
 * test run. Mail::fake() for the same reason on the mail side.
 *
 * The code itself is read out of the intercepted gateway payload and never from the
 * application: nothing anywhere will hand the digits back, which is half the point of
 * the design, and taking them off the wire is the only way a test can know them.
 *
 * The negative assertions are the ones that matter most here. This screen runs on
 * event day against a live table holding real receipts, and it must move no money and
 * no stock on its own: a shirt reaching a pair of hands changes neither what was
 * charged nor how many units were promised.
 */
class EventCollectionTest extends TestCase
{
    use RefreshDatabase;

    /** Reading the screen. */
    private const CAN_VIEW = ['attendance.view'];

    /** Reading it and pressing the buttons. */
    private const CAN_COLLECT = ['attendance.view', 'attendance.update'];

    /** Reading it and taking the file. */
    private const CAN_EXPORT = ['attendance.view', 'participants.export'];

    private const COLLECTOR = [
        'collector' => CollectionHandover::KIND_OTHER,
        'collector_name' => 'Siti Nurhaliza',
        'collector_ic' => '880202105566',
        'collector_phone' => '0178591411',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        $this->infobipSettings();
        $this->fakeInfobip();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** A complete Infobip profile. Credentials that exist and reach nothing. */
    private function infobipSettings(): void
    {
        Setting::write('integration.sms.enabled', '1', 'integration.sms');
        Setting::write('integration.sms.provider', SmsSettings::PROVIDER_INFOBIP, 'integration.sms');
        Setting::write('integration.sms.sender_id', '62033', 'integration.sms');
        Setting::write('integration.sms.base_url', 'fake.api.infobip.test', 'integration.sms');
        Setting::write('integration.sms.api_key', 'not-a-real-key-' . uniqid(), 'integration.sms');
    }

    private function fakeInfobip(): void
    {
        Http::fake(fn () => Http::response([
            'messages' => [[
                'messageId' => 'msg-' . uniqid(),
                'status' => ['groupName' => 'PENDING', 'description' => 'Message sent to next instance'],
            ]],
        ]));
    }

    /** The code out of the last message handed to the gateway. */
    private function sentCode(): string
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'No message was handed to the gateway.');

        $text = (string) data_get($recorded[count($recorded) - 1][0]->data(), 'messages.0.text', '');

        $this->assertMatchesRegularExpression('/^\d{6} /', $text, 'The message did not start with a six digit code.');

        return substr($text, 0, 6);
    }

    private function messagesSent(): int
    {
        return count(Http::recorded());
    }

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'collect-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA',
            'category' => 'Sports',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'location' => 'Sibu',
            'fee' => null,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /**
     * A shirt that is handed over, in three sizes, and an insurance line that is not.
     *
     * S and M inherit the add-on price, so swapping one for the other moves no money.
     * The 5XL carries a surcharge, which is the option staff are refused.
     *
     * @return array{shirt: EventAddon, insurance: EventAddon, small: EventAddonVariant, medium: EventAddonVariant, huge: EventAddonVariant}
     */
    private function catalogue(Event $event): array
    {
        $shirt = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'EVENT TEE',
            'price' => 40,
            'is_required' => true,
            'is_active' => true,
            'is_handed_over' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
            'sort_order' => 1,
        ]);

        $small = EventAddonVariant::create([
            'event_addon_id' => $shirt->id,
            'label' => 'S',
            'price' => null,
            'stock' => 50,
            'sort_order' => 1,
        ]);

        $medium = EventAddonVariant::create([
            'event_addon_id' => $shirt->id,
            'label' => 'M',
            'price' => null,
            'stock' => 50,
            'sort_order' => 2,
        ]);

        $huge = EventAddonVariant::create([
            'event_addon_id' => $shirt->id,
            'label' => '5XL',
            'price' => 10,
            'stock' => 50,
            'sort_order' => 3,
        ]);

        // Nothing leaves a table for this one, so it has nothing to collect.
        $insurance = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'PERSONAL INSURANCE',
            'price' => 5,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
            'sort_order' => 2,
        ]);

        EventAddonVariant::create([
            'event_addon_id' => $insurance->id,
            'label' => 'Standard',
            'price' => null,
            'stock' => null,
            'sort_order' => 1,
        ]);

        return [
            'shirt' => $shirt->fresh(),
            'insurance' => $insurance->fresh(),
            'small' => $small,
            'medium' => $medium,
            'huge' => $huge,
        ];
    }

    private function registration(Event $event, string $reference, array $overrides = []): EventRegistration
    {
        return EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Keluarga Rahman',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 0,
            'addons_total' => 240,
            'amount' => 240,
            'amount_paid' => 240,
        ]);
    }

    private function person(EventRegistration $registration, string $name, string $card, array $overrides = []): EventParticipant
    {
        return EventParticipant::create($overrides + [
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => $name,
            'ic_number' => $card,
            'phone' => '0128508124',
            'email' => Str::slug($name) . '-' . uniqid() . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);
    }

    /** A paid line naming one person and one size, the way a registration writes it. */
    private function line(
        EventRegistration $registration,
        EventParticipant $person,
        EventAddon $addon,
        ?EventAddonVariant $variant,
        float $price = 40,
    ): EventRegistrationAddon {
        $line = EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_participant_id' => $person->id,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => $variant?->id,
            'name' => $addon->name,
            'variant_label' => $variant?->label,
            'unit_price' => $price,
            'quantity' => 1,
            'line_total' => $price,
        ]);

        if ($variant !== null) {
            // What the registration form does when a size is chosen.
            $variant->increment('stock_taken');
        }

        return $line;
    }

    /**
     * One event, one paid grouping of six, each with a shirt line.
     *
     * $sizes names the size each person holds, or null for somebody who never answered
     * the size link. The insurance line is deliberately bulk, naming nobody, because
     * that is how a non-collected add-on sits on a real entry.
     *
     * @param  array<int, EventAddonVariant|null>  $sizes
     * @return array{event: Event, registration: EventRegistration, people: array<int, EventParticipant>, catalogue: array<string, mixed>}
     */
    private function grouping(array $sizes = [], array $registrationOverrides = []): array
    {
        $event = $this->event();
        $catalogue = $this->catalogue($event);
        $registration = $this->registration($event, 'REG-2026-0001', $registrationOverrides);

        $people = [];

        foreach (range(1, 6) as $index) {
            $person = $this->person(
                $registration,
                'Peserta ' . $index,
                sprintf('90010101000%d', $index),
            );

            /*
             | array_key_exists rather than ?? on purpose: an explicit null means
             | "this person never answered the size link", and ?? would read that as
             | an absent key and quietly hand them an S.
             */
            $size = array_key_exists($index - 1, $sizes) ? $sizes[$index - 1] : $catalogue['small'];

            if ($size !== null) {
                $this->line($registration, $person, $catalogue['shirt'], $size);
            } else {
                // The shape the totals recalculation left behind: the charge is on the
                // line and the size is not.
                $this->line($registration, $person, $catalogue['shirt'], null);
            }

            $people[] = $person;
        }

        // Bulk, naming nobody, and not handed over either way.
        EventRegistrationAddon::create([
            'event_registration_id' => $registration->id,
            'event_addon_id' => $catalogue['insurance']->id,
            'name' => $catalogue['insurance']->name,
            'variant_label' => 'Standard',
            'unit_price' => 5,
            'quantity' => 6,
            'line_total' => 30,
        ]);

        return [
            'event' => $event,
            'registration' => $registration->fresh(),
            'people' => $people,
            'catalogue' => $catalogue,
        ];
    }

    /**
     * A user holding exactly the named permissions and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, so the
     * screen is reached the way an office account reaches it.
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

        // admin.access is always granted: without it EnsureUserCanAccessAdmin signs
        // the session out before any permission on the route is consulted.
        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Event', 'module' => 'Collection', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Asking
     * ------------------------------------------------------------------ */

    private function screen(array $filters = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_COLLECT))
            ->get(route('admin.event.collection', $filters));
    }

    private function handOver(EventRegistration $registration, array $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_COLLECT))
            ->from(route('admin.event.collection'))
            ->post(route('admin.event.collection.hand-over', $registration), $body);
    }

    private function sendCode(EventRegistration $registration, array $overrides = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->userWith(self::CAN_COLLECT))
            ->postJson(route('admin.event.collection.code', $registration), $overrides + [
                'collector_name' => self::COLLECTOR['collector_name'],
                'collector_ic' => self::COLLECTOR['collector_ic'],
                'collector_phone' => self::COLLECTOR['collector_phone'],
            ]);
    }

    /** The row key the screen and the form use for one person and one item. */
    private function key(EventParticipant $person, EventAddon $addon): string
    {
        return $person->id . ':' . $addon->id;
    }

    /**
     * Every money and stock figure that must not move, read back from the database.
     *
     * @return array<string, mixed>
     */
    private function figures(): array
    {
        return [
            'registrations' => EventRegistration::query()
                ->orderBy('id')
                ->get(['id', 'registration_fee', 'addons_total', 'amount', 'amount_paid', 'refunded_amount', 'payment_status', 'status'])
                ->toArray(),
            'lines' => EventRegistrationAddon::query()
                ->orderBy('id')
                ->get(['id', 'unit_price', 'quantity', 'line_total'])
                ->toArray(),
            'stock_taken' => EventAddonVariant::query()->orderBy('id')->pluck('stock_taken')->all(),
            'stock' => EventAddonVariant::query()->orderBy('id')->pluck('stock')->all(),
            'payments' => DB::table('event_registration_payments')->count(),
        ];
    }

    /* ---------------------------------------------------------------------
     | 1. What belongs on the screen
     * ------------------------------------------------------------------ */

    public function test_only_addons_marked_handed_over_appear(): void
    {
        $world = $this->grouping();

        $response = $this->screen(['event' => $world['event']->id]);

        $response->assertOk();
        $response->assertSee('EVENT TEE');

        // The insurance line is on the entry, is paid for, and has nothing to collect.
        $response->assertDontSee('PERSONAL INSURANCE');

        // Six people, one collectable item each.
        $this->assertSame(6, $response->viewData('figures')['total']);
        $this->assertSame(0, $response->viewData('figures')['collected']);
    }

    public function test_an_event_that_hands_nothing_over_draws_an_empty_screen(): void
    {
        $event = $this->event();
        $catalogue = $this->catalogue($event);

        // The one collectable item is withdrawn from the catalogue entirely.
        $catalogue['shirt']->update(['is_handed_over' => false]);

        $registration = $this->registration($event, 'REG-2026-0002');
        $this->person($registration, 'Peserta Satu', '900101010001');

        $response = $this->screen(['event' => $event->id]);

        // An empty screen, not an error, and not a division by anything.
        $response->assertOk();
        $response->assertSee('Nothing to hand over');
        $this->assertSame(0, $response->viewData('figures')['total']);
        $this->assertSame(0, $response->viewData('figures')['collected']);
        $this->assertCount(0, $response->viewData('participants'));
    }

    public function test_a_withdrawn_addon_is_still_handed_over(): void
    {
        $world = $this->grouping();

        // Withdrawing an add-on stops new sales. It does not unsell the shirts already
        // paid for, and those still have to reach the people who bought them.
        $world['catalogue']['shirt']->update(['is_active' => false]);

        $this->screen(['event' => $world['event']->id])
            ->assertOk()
            ->assertSee('EVENT TEE');
    }

    /* ---------------------------------------------------------------------
     | 2. Collecting your own
     * ------------------------------------------------------------------ */

    public function test_a_participant_collecting_their_own_item_needs_no_code(): void
    {
        $world = $this->grouping();
        $person = $world['people'][0];
        $admin = $this->userWith(self::CAN_COLLECT);
        $before = $this->figures();

        $response = $this->handOver($world['registration'], [
            'rows' => [$this->key($person, $world['catalogue']['shirt'])],
        ], $admin);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status');

        // Not one message, and not one code row.
        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(0, CollectionVerification::query()->count());

        $handover = CollectionHandover::query()->sole();

        $this->assertTrue($handover->byBuyer());
        $this->assertTrue($handover->isAssured());
        $this->assertFalse($handover->wasOverridden());
        $this->assertFalse($handover->wasHandedOverUnpaid());
        $this->assertSame($person->full_name, $handover->collector_name);
        $this->assertSame($person->ic_number, $handover->collector_ic);
        $this->assertSame($admin->logLabel(), $handover->confirmed_by_label);

        // Against the line, which is the one item: the other five are untouched.
        $this->assertSame(EventRegistrationAddon::class, $handover->collectable_type);
        $this->assertSame(1, CollectionHandover::query()->count());

        $this->assertSame($before, $this->figures());
    }

    public function test_collecting_moves_no_stock_on_its_own(): void
    {
        $world = $this->grouping();
        $small = $world['catalogue']['small'];

        // Six shirts were committed to six people when the sizes were chosen.
        $this->assertSame(6, $small->fresh()->stock_taken);

        $this->handOver($world['registration'], [
            'rows' => collect($world['people'])
                ->map(fn (EventParticipant $person) => $this->key($person, $world['catalogue']['shirt']))
                ->all(),
            ...self::COLLECTOR,
            'override_reason' => 'Testing that a handover moves nothing.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, CollectionHandover::query()->count());

        // stock_taken counts units promised to people. A shirt reaching a pair of
        // hands does not change how many were promised.
        $this->assertSame(6, $small->fresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | 3. One representative, six rows, one code
     * ------------------------------------------------------------------ */

    public function test_a_representative_collecting_every_row_is_recorded_on_all_six(): void
    {
        $world = $this->grouping();
        $registration = $world['registration'];

        // The representative is one of the six, which is the common case.
        $representative = $world['people'][0];

        $this->sendCode($registration, [
            'collector_name' => $representative->full_name,
            'collector_ic' => $representative->ic_number,
            'collector_phone' => '0178591411',
        ])->assertOk();

        $this->assertSame(1, $this->messagesSent());

        $response = $this->handOver($registration, [
            'rows' => collect($world['people'])
                ->map(fn (EventParticipant $person) => $this->key($person, $world['catalogue']['shirt']))
                ->all(),
            'collector' => CollectionHandover::KIND_OTHER,
            'collector_name' => $representative->full_name,
            'collector_ic' => $representative->ic_number,
            'collector_phone' => '0178591411',
            'code' => $this->sentCode(),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status');

        $handovers = CollectionHandover::query()->orderBy('id')->get();

        $this->assertCount(6, $handovers);

        // One person named on all six, and one verification covering all six: that is
        // what answers an absent member who says months later that they never had
        // theirs.
        $this->assertSame([$representative->full_name], $handovers->pluck('collector_name')->unique()->all());
        $this->assertCount(1, $handovers->pluck('collection_verification_id')->unique());
        $this->assertNotNull($handovers->first()->collection_verification_id);

        foreach ($handovers as $handover) {
            $this->assertTrue($handover->isVerified());
            $this->assertTrue($handover->isAssured());
            $this->assertFalse($handover->wasOverridden());
        }

        /*
         | Their own row reads as a person collecting their own, the other five as a
         | third party. Decided off the identity card rather than off the radio button,
         | because recording five other people's shirts as self-collection would be a
         | great deal worse than the one row it gets right.
         */
        $kinds = $handovers->pluck('collector_kind')->countBy()->all();

        $this->assertSame(1, $kinds[CollectionHandover::KIND_BUYER] ?? 0);
        $this->assertSame(5, $kinds[CollectionHandover::KIND_OTHER] ?? 0);

        // One message for the whole family, not six.
        $this->assertSame(1, $this->messagesSent());
    }

    public function test_a_third_party_without_a_verified_code_is_refused(): void
    {
        $world = $this->grouping();
        $before = $this->figures();

        $response = $this->handOver($world['registration'], [
            'rows' => [$this->key($world['people'][0], $world['catalogue']['shirt'])],
            ...self::COLLECTOR,
        ]);

        $response->assertSessionHasErrors('code');

        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertSame($before, $this->figures());
    }

    public function test_a_third_party_is_refused_without_their_own_details(): void
    {
        $world = $this->grouping();

        $this->handOver($world['registration'], [
            'rows' => [$this->key($world['people'][0], $world['catalogue']['shirt'])],
            'collector' => CollectionHandover::KIND_OTHER,
        ])->assertSessionHasErrors(['collector_name', 'collector_ic', 'collector_phone']);

        $this->assertSame(0, CollectionHandover::query()->count());
    }

    /* ---------------------------------------------------------------------
     | 4. What a code is worth
     * ------------------------------------------------------------------ */

    public function test_a_wrong_code_spends_an_attempt_and_the_limit_burns_it(): void
    {
        Setting::write('integration.sms.collection_code_max_attempts', '3', 'integration.sms');

        $world = $this->grouping();
        $registration = $world['registration'];
        $row = [$this->key($world['people'][0], $world['catalogue']['shirt'])];

        $this->sendCode($registration)->assertOk();

        $real = $this->sentCode();
        $wrong = $real === '000000' ? '111111' : '000000';

        for ($try = 1; $try <= 2; $try++) {
            $this->handOver($registration, ['rows' => $row, ...self::COLLECTOR, 'code' => $wrong])
                ->assertSessionHasErrors('code');

            $this->assertSame($try, (int) CollectionVerification::query()->sole()->attempts);
        }

        // The third wrong entry is the last one the code survives.
        $this->handOver($registration, ['rows' => $row, ...self::COLLECTOR, 'code' => $wrong])
            ->assertSessionHasErrors('code');

        $verification = CollectionVerification::query()->sole();

        $this->assertSame(3, (int) $verification->attempts);
        $this->assertNotNull($verification->burned_at);
        $this->assertFalse($verification->isLive());

        // And the real code no longer works, because the code is dead rather than the
        // guess being wrong.
        $this->handOver($registration, ['rows' => $row, ...self::COLLECTOR, 'code' => $real])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, CollectionHandover::query()->count());

        /*
         | Three entries recorded, and not one of them records what was typed. The
         | fourth found no live code to check at all, so there was nothing to log an
         | attempt against — which is also why it did not cost an attempt.
         */
        $this->assertSame(3, CollectionVerificationAttempt::query()->count());

        foreach (CollectionVerificationAttempt::query()->get() as $attempt) {
            $this->assertNotContains($real, array_map('strval', $attempt->getAttributes()));
        }
    }

    public function test_an_expired_code_is_refused(): void
    {
        Setting::write('integration.sms.collection_code_expiry_minutes', '10', 'integration.sms');

        $world = $this->grouping();
        $registration = $world['registration'];

        $this->sendCode($registration)->assertOk();

        $code = $this->sentCode();

        $this->travel(11)->minutes();

        $this->handOver($registration, [
            'rows' => [$this->key($world['people'][0], $world['catalogue']['shirt'])],
            ...self::COLLECTOR,
            'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertNotNull(CollectionVerification::query()->sole()->burned_at);
    }

    public function test_a_code_for_one_entry_cannot_complete_another(): void
    {
        $world = $this->grouping();
        $event = $world['event'];
        $catalogue = $world['catalogue'];

        $other = $this->registration($event, 'REG-2026-0099');
        $stranger = $this->person($other, 'Orang Lain', '770707077777');
        $this->line($other, $stranger, $catalogue['shirt'], $catalogue['medium']);

        // The code is issued for the grouping.
        $this->sendCode($world['registration'])->assertOk();

        $code = $this->sentCode();

        // ...and entered against the other entry, where there is no live code at all.
        $this->handOver($other, [
            'rows' => [$this->key($stranger, $catalogue['shirt'])],
            ...self::COLLECTOR,
            'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertSame(0, CollectionHandover::query()->count());

        // The grouping's own code is untouched by the attempt: it was never looked at.
        $verification = CollectionVerification::query()->sole();

        $this->assertSame(0, (int) $verification->attempts);
        $this->assertTrue($verification->isLive());
    }

    public function test_a_double_press_on_send_issues_no_second_code(): void
    {
        Setting::write('integration.sms.collection_code_cooldown_minutes', '2', 'integration.sms');

        $world = $this->grouping();

        $this->sendCode($world['registration'])->assertOk();
        $this->sendCode($world['registration'])->assertStatus(422);

        $this->assertSame(1, $this->messagesSent());
        $this->assertSame(1, CollectionVerification::query()->count());
    }

    /* ---------------------------------------------------------------------
     | 5. Taking the choice at the counter
     * ------------------------------------------------------------------ */

    public function test_recording_a_missing_choice_at_handover_moves_stock_exactly(): void
    {
        // Nobody answered the size link, so every line carries the charge and no size.
        $world = $this->grouping([null, null, null, null, null, null]);
        $catalogue = $world['catalogue'];
        $person = $world['people'][0];

        $this->assertSame(0, $catalogue['medium']->fresh()->stock_taken);

        $response = $this->handOver($world['registration'], [
            'rows' => [$this->key($person, $catalogue['shirt'])],
            'sizes' => [$person->id => [$catalogue['shirt']->id => $catalogue['medium']->id]],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status');

        // Exactly one unit, onto the line that already carried the charge, and no
        // second line created.
        $this->assertSame(1, $catalogue['medium']->fresh()->stock_taken);
        $this->assertSame(0, $catalogue['small']->fresh()->stock_taken);

        $lines = EventRegistrationAddon::query()
            ->where('event_participant_id', $person->id)
            ->get();

        $this->assertCount(1, $lines);
        $this->assertSame($catalogue['medium']->id, $lines->first()->event_addon_variant_id);
        $this->assertSame('M', $lines->first()->variant_label);

        // The charge is what it was. Recording a size is not a purchase.
        $this->assertSame('40.00', $lines->first()->unit_price);
        $this->assertSame('40.00', $lines->first()->line_total);

        $this->assertSame(1, CollectionHandover::query()->count());

        $entry = $world['registration']->fresh();

        $this->assertSame('240.00', $entry->amount);
        $this->assertSame('240.00', $entry->amount_paid);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $entry->payment_status);
    }

    public function test_a_choice_for_an_item_this_screen_does_not_hand_over_is_ignored(): void
    {
        $world = $this->grouping([null]);
        $catalogue = $world['catalogue'];
        $person = $world['people'][0];

        $insuranceOption = EventAddonVariant::query()
            ->where('event_addon_id', $catalogue['insurance']->id)
            ->sole();

        // A counter handing out shirts has no business answering for the insurance
        // line, so the pair is never looked at — and the shirt in the same press still
        // goes through.
        $this->handOver($world['registration'], [
            'rows' => [$this->key($person, $catalogue['shirt'])],
            'sizes' => [
                $person->id => [
                    $catalogue['shirt']->id => $catalogue['small']->id,
                    $catalogue['insurance']->id => $insuranceOption->id,
                ],
            ],
        ])->assertSessionHas('status');

        $this->assertSame(1, CollectionHandover::query()->count());

        // No line was written for the item this screen does not hand over.
        $this->assertSame(
            0,
            EventRegistrationAddon::query()
                ->where('event_participant_id', $person->id)
                ->where('event_addon_id', $catalogue['insurance']->id)
                ->count(),
        );
    }

    public function test_a_differently_priced_option_is_refused_at_the_counter(): void
    {
        $world = $this->grouping([null, null, null, null, null, null]);
        $catalogue = $world['catalogue'];
        $person = $world['people'][0];
        $before = $this->figures();

        // The 5XL costs ten ringgit more than what is on record, so recording it here
        // would change what this entry owes. Staff are refused and told where to take
        // the difference.
        $response = $this->handOver($world['registration'], [
            'rows' => [$this->key($person, $catalogue['shirt'])],
            'sizes' => [$person->id => [$catalogue['shirt']->id => $catalogue['huge']->id]],
        ]);

        $response->assertSessionHasErrors('sizes.' . $person->id . '.' . $catalogue['shirt']->id);

        // Nothing written: not the size, not the stock, not the handover.
        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertSame($before, $this->figures());
    }

    public function test_a_row_with_no_line_at_all_is_refused_until_a_choice_is_made(): void
    {
        $event = $this->event();
        $catalogue = $this->catalogue($event);
        $registration = $this->registration($event, 'REG-2026-0003', [
            'addons_total' => 0,
            'registration_fee' => 40,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        // The fee-era shape: the RM 40.00 sits in registration_fee and there is no
        // add-on line at all.
        $person = $this->person($registration, 'Peserta Lama', '880808088888');

        $this->handOver($registration, [
            'rows' => [$this->key($person, $catalogue['shirt'])],
        ])->assertSessionHasErrors('rows');

        $this->assertSame(0, CollectionHandover::query()->count());

        // With a size chosen in the same press, the line is created at 0.00 and the
        // handover goes through.
        $this->handOver($registration, [
            'rows' => [$this->key($person, $catalogue['shirt'])],
            'sizes' => [$person->id => [$catalogue['shirt']->id => $catalogue['small']->id]],
        ])->assertSessionHas('status');

        $this->assertSame(1, CollectionHandover::query()->count());

        $line = EventRegistrationAddon::query()->where('event_participant_id', $person->id)->sole();

        // 0.00 on purpose: this person already paid for the shirt through the event
        // fee, and pricing the new line would charge it twice.
        $this->assertSame('0.00', $line->unit_price);
        $this->assertSame('0.00', $line->line_total);

        $entry = $registration->fresh();

        $this->assertSame('40.00', $entry->amount);
        $this->assertSame('40.00', $entry->amount_paid);
    }

    /* ---------------------------------------------------------------------
     | 6. The two overrides
     * ------------------------------------------------------------------ */

    public function test_an_unpaid_entry_is_refused_without_an_override_and_completes_with_one(): void
    {
        $world = $this->grouping([], [
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
            'amount_paid' => 40,
        ]);

        $registration = $world['registration'];
        $row = [$this->key($world['people'][0], $world['catalogue']['shirt'])];
        $before = $this->figures();

        // Not silently allowed.
        $refused = $this->handOver($registration, ['rows' => $row]);

        $refused->assertSessionHasErrors('payment_override_reason');
        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertSame($before, $this->figures());

        // ...and not flatly refused either: six people owing RM 200.00 will turn up on
        // the day and a counter that cannot take the balance has to be able to decide.
        $allowed = $this->handOver($registration, [
            'rows' => $row,
            'payment_override_reason' => 'Balance by transfer tonight, agreed with the organiser.',
        ]);

        $allowed->assertSessionHasNoErrors();
        $allowed->assertSessionHas('warning');

        $handover = CollectionHandover::query()->sole();

        $this->assertTrue($handover->wasHandedOverUnpaid());
        $this->assertSame('Balance by transfer tonight, agreed with the organiser.', $handover->payment_override_reason);

        // Which is a different fact from who was standing there, and recorded as one.
        $this->assertNull($handover->override_reason);
        $this->assertFalse($handover->wasOverridden());

        // And the money is exactly where it was. Handing goods over is not a payment.
        $entry = $registration->fresh();

        $this->assertSame('240.00', $entry->amount);
        $this->assertSame('40.00', $entry->amount_paid);
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $entry->payment_status);
        $this->assertSame(0, DB::table('event_registration_payments')->count());
    }

    public function test_the_payment_override_is_not_recorded_on_a_settled_entry(): void
    {
        $world = $this->grouping();

        $this->handOver($world['registration'], [
            'rows' => [$this->key($world['people'][0], $world['catalogue']['shirt'])],
            'payment_override_reason' => 'Typed by habit on an entry that owes nothing.',
        ])->assertSessionHas('status');

        // An override on a clean record would mark it as an exception it is not.
        $this->assertNull(CollectionHandover::query()->sole()->payment_override_reason);
    }

    public function test_the_sms_override_records_its_reason_and_marks_the_row_unverified(): void
    {
        $world = $this->grouping();

        $response = $this->handOver($world['registration'], [
            'rows' => [$this->key($world['people'][1], $world['catalogue']['shirt'])],
            ...self::COLLECTOR,
            'override_reason' => 'No signal in the hall.',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('warning');

        $handover = CollectionHandover::query()->sole();

        $this->assertTrue($handover->byThirdParty());
        $this->assertTrue($handover->wasOverridden());
        $this->assertFalse($handover->isVerified());

        // The record that has to be read with suspicion, and says so.
        $this->assertFalse($handover->isAssured());
        $this->assertSame('No signal in the hall.', $handover->override_reason);
        $this->assertNull($handover->collection_verification_id);
        $this->assertSame(self::COLLECTOR['collector_name'], $handover->collector_name);

        // Nothing was texted, because the override is what replaces the code.
        $this->assertSame(0, $this->messagesSent());
    }

    /* ---------------------------------------------------------------------
     | 7. Pressing twice
     * ------------------------------------------------------------------ */

    public function test_an_already_collected_row_cannot_be_collected_again(): void
    {
        $world = $this->grouping();
        $row = [$this->key($world['people'][0], $world['catalogue']['shirt'])];

        $this->handOver($world['registration'], ['rows' => $row])->assertSessionHas('status');

        $first = CollectionHandover::query()->sole();

        $this->travel(5)->minutes();

        $again = $this->handOver($world['registration'], ['rows' => $row]);

        // A warning, not an error: a double press, a reload or two people working the
        // same queue is the ordinary way to arrive here.
        $again->assertSessionHasNoErrors();
        $again->assertSessionHas('warning');

        $this->assertSame(1, CollectionHandover::query()->count());
        $this->assertSame(
            $first->collected_at->toDateTimeString(),
            CollectionHandover::query()->sole()->collected_at->toDateTimeString(),
        );
    }

    public function test_a_batch_over_an_already_collected_row_still_writes_the_rest(): void
    {
        $world = $this->grouping();
        $shirt = $world['catalogue']['shirt'];

        $this->handOver($world['registration'], [
            'rows' => [$this->key($world['people'][0], $shirt)],
        ])->assertSessionHas('status');

        // The whole family now turns up, including the one already dealt with.
        $this->handOver($world['registration'], [
            'rows' => collect($world['people'])
                ->map(fn (EventParticipant $person) => $this->key($person, $shirt))
                ->all(),
            ...self::COLLECTOR,
            'override_reason' => 'Gateway down at the venue.',
        ])->assertSessionHas('warning');

        // Six rows, six handovers, and the first one is not rewritten.
        $this->assertSame(6, CollectionHandover::query()->count());
        $this->assertSame(1, CollectionHandover::query()->whereNull('override_reason')->count());
    }

    /* ---------------------------------------------------------------------
     | 8. Who may press it
     * ------------------------------------------------------------------ */

    public function test_the_handover_is_refused_without_the_permission(): void
    {
        $world = $this->grouping();

        $this->handOver(
            $world['registration'],
            ['rows' => [$this->key($world['people'][0], $world['catalogue']['shirt'])]],
            $this->userWith(self::CAN_VIEW),
        )->assertForbidden();

        $this->assertSame(0, CollectionHandover::query()->count());
    }

    public function test_the_handover_and_the_code_are_refused_on_get(): void
    {
        $world = $this->grouping();
        $user = $this->userWith(self::CAN_COLLECT);

        $this->actingAs($user)
            ->get(route('admin.event.collection.hand-over', $world['registration']))
            ->assertStatus(405);

        $this->actingAs($user)
            ->get(route('admin.event.collection.code', $world['registration']))
            ->assertStatus(405);

        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertSame(0, $this->messagesSent());
    }

    public function test_the_screen_is_refused_without_the_view_permission(): void
    {
        $world = $this->grouping();

        $this->actingAs($this->userWith([]))
            ->get(route('admin.event.collection', ['event' => $world['event']->id]))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | 9. Finding somebody at the counter
     * ------------------------------------------------------------------ */

    public function test_search_finds_a_row_by_identity_card_name_and_reference(): void
    {
        $world = $this->grouping();
        $event = $world['event'];

        $other = $this->registration($event, 'REG-2026-0500', ['team_name' => 'Kelab Bola']);
        $stranger = $this->person($other, 'Zulkifli Bin Hassan', '550505055555');
        $this->line($other, $stranger, $world['catalogue']['shirt'], $world['catalogue']['medium']);

        // By card number, which is what a scanner types.
        $byCard = $this->screen(['event' => $event->id, 'q' => '550505055555']);
        $byCard->assertOk();
        $byCard->assertSee('Zulkifli Bin Hassan');
        $byCard->assertDontSee('Peserta 1');
        $this->assertSame(1, $byCard->viewData('figures')['total']);

        // By name.
        $byName = $this->screen(['event' => $event->id, 'q' => 'Zulkifli']);
        $byName->assertSee('Zulkifli Bin Hassan');
        $this->assertSame(1, $byName->viewData('figures')['total']);

        // By reference, which brings the whole entry back.
        $byReference = $this->screen(['event' => $event->id, 'q' => 'REG-2026-0001']);
        $byReference->assertSee('Peserta 1');
        $byReference->assertDontSee('Zulkifli Bin Hassan');
        $this->assertSame(6, $byReference->viewData('figures')['total']);
    }

    /* ---------------------------------------------------------------------
     | 10. The figures follow the filters
     * ------------------------------------------------------------------ */

    public function test_the_figures_respect_the_active_filters(): void
    {
        // The first three never answered the size link; the other three are on S.
        $world = $this->grouping([null, null, null]);

        $shirt = $world['catalogue']['shirt'];

        // Two of the six go out.
        $this->handOver($world['registration'], [
            'rows' => [
                $this->key($world['people'][3], $shirt),
                $this->key($world['people'][4], $shirt),
            ],
        ])->assertSessionHas('status');

        $everything = $this->screen(['event' => $world['event']->id]);

        $this->assertSame(6, $everything->viewData('figures')['total']);
        $this->assertSame(2, $everything->viewData('figures')['collected']);
        $this->assertSame(4, $everything->viewData('figures')['outstanding']);

        // Three of them never answered the size link.
        $this->assertSame(3, $everything->viewData('figures')['missing']);

        // Narrowed to what has gone out, the figures describe exactly that.
        $collected = $this->screen(['event' => $world['event']->id, 'state' => 'collected']);

        $this->assertSame(2, $collected->viewData('figures')['total']);
        $this->assertSame(2, $collected->viewData('figures')['collected']);
        $this->assertSame(0, $collected->viewData('figures')['outstanding']);

        // ...and to what has not.
        $outstanding = $this->screen(['event' => $world['event']->id, 'state' => 'outstanding']);

        $this->assertSame(4, $outstanding->viewData('figures')['total']);
        $this->assertSame(0, $outstanding->viewData('figures')['collected']);

        // Only the people still owing a choice.
        $missing = $this->screen(['event' => $world['event']->id, 'choice' => 'missing']);

        $this->assertSame(3, $missing->viewData('figures')['total']);
        $this->assertSame(3, $missing->viewData('figures')['missing']);
    }

    public function test_the_payment_filter_narrows_to_the_entries_that_owe(): void
    {
        $world = $this->grouping();
        $event = $world['event'];

        $owing = $this->registration($event, 'REG-2026-0600', [
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
            'amount_paid' => 40,
        ]);
        $person = $this->person($owing, 'Peserta Hutang', '660606066666');
        $this->line($owing, $person, $world['catalogue']['shirt'], $world['catalogue']['small']);

        $all = $this->screen(['event' => $event->id]);
        $this->assertSame(7, $all->viewData('figures')['total']);

        $unsettled = $this->screen(['event' => $event->id, 'payment' => 'owing']);
        $unsettled->assertSee('Peserta Hutang');
        $this->assertSame(1, $unsettled->viewData('figures')['total']);

        $settled = $this->screen(['event' => $event->id, 'payment' => 'paid']);
        $settled->assertDontSee('Peserta Hutang');
        $this->assertSame(6, $settled->viewData('figures')['total']);
    }

    public function test_a_cancelled_entry_is_left_off(): void
    {
        $world = $this->grouping([], ['status' => EventRegistration::STATUS_CANCELLED]);

        $response = $this->screen(['event' => $world['event']->id]);

        $response->assertOk();
        $this->assertSame(0, $response->viewData('figures')['total']);
    }

    /* ---------------------------------------------------------------------
     | 11. The printed fallback
     * ------------------------------------------------------------------ */

    public function test_the_csv_export_groups_by_option(): void
    {
        // One person never answered; the other five are on S to start with.
        $world = $this->grouping([null]);

        $catalogue = $world['catalogue'];

        // Two on M, three on S, one with nothing recorded.
        EventRegistrationAddon::query()
            ->where('event_participant_id', $world['people'][1]->id)
            ->update(['event_addon_variant_id' => $catalogue['medium']->id, 'variant_label' => 'M']);

        EventRegistrationAddon::query()
            ->where('event_participant_id', $world['people'][2]->id)
            ->update(['event_addon_variant_id' => $catalogue['medium']->id, 'variant_label' => 'M']);

        $response = $this->actingAs($this->userWith(self::CAN_EXPORT))
            ->get(route('admin.event.collection.export', ['event' => $world['event']->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $response->streamedContent()))));

        // The header, then six rows.
        $this->assertCount(7, $lines);

        $options = [];

        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line);
            $options[] = $cells[1];
        }

        /*
         | Grouped: every M together, then every S, then the one nobody has answered
         | for. The file is read beside a stack of boxes, so the order is the order the
         | boxes are in and the unanswered row is at the bottom where it cannot be
         | missed rather than sorted to the top by an empty string.
         */
        $this->assertSame(['M', 'M', 'S', 'S', 'S', 'Not recorded'], $options);
    }

    public function test_the_export_refuses_to_cover_every_event(): void
    {
        $this->grouping();

        $this->actingAs($this->userWith(self::CAN_EXPORT))
            ->from(route('admin.event.collection'))
            ->get(route('admin.event.collection.export'))
            ->assertRedirect(route('admin.event.collection'))
            ->assertSessionHas('warning');
    }

    public function test_the_export_is_refused_without_the_export_permission(): void
    {
        $world = $this->grouping();

        $this->actingAs($this->userWith(self::CAN_COLLECT))
            ->get(route('admin.event.collection.export', ['event' => $world['event']->id]))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | 12. What it costs, and what it writes
     * ------------------------------------------------------------------ */

    public function test_the_screen_costs_the_same_whatever_the_event_holds(): void
    {
        $world = $this->grouping();
        $event = $world['event'];
        $user = $this->userWith(self::CAN_COLLECT);

        // One request before either measurement, because the first one of a process
        // also reads the settings and the sidebar and then caches them.
        $this->screen(['event' => $event->id], $user)->assertOk();

        $small = $this->queriesFor(fn () => $this->screen(['event' => $event->id], $user)->assertOk());

        // Ten more entries on the same event, each with a person and a shirt.
        for ($i = 1; $i <= 10; $i++) {
            $entry = $this->registration($event, sprintf('REG-2026-07%02d', $i));
            $person = $this->person($entry, 'Peserta Tambahan ' . $i, sprintf('9505050505%02d', $i));
            $this->line($entry, $person, $world['catalogue']['shirt'], $world['catalogue']['small']);
        }

        $large = $this->queriesFor(fn () => $this->screen(['event' => $event->id], $user)->assertOk());

        // Aggregates and eager loads, so the cost is flat. A query per row would show
        // here as a difference.
        $this->assertSame(
            count($small),
            count($large),
            'The collection screen is running more queries as rows are added.',
        );

        /*
         | And the absolute figure, so a per-row query added later is caught even on
         | its own. Fifteen on a warm request: three to find which events hand anything
         | over and what, two to page the people, seven eager loads down to the
         | handover on each line, and three aggregates for the counters.
         */
        $this->assertLessThanOrEqual(16, count($large), 'The collection screen has grown a query budget problem.');
    }

    public function test_the_screen_writes_nothing(): void
    {
        $world = $this->grouping();

        // Built before anything is measured, so creating it is not mistaken for the
        // screen writing.
        $user = $this->userWith(self::CAN_COLLECT);

        $before = $this->figures();

        $queries = $this->queriesFor(fn () => $this->screen(['event' => $world['event']->id], $user)->assertOk());

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i',
                $sql,
                'The collection screen wrote to the database while only being read.',
            );
        }

        $this->assertSame($before, $this->figures());
    }

    /**
     * Every statement one closure causes.
     *
     * @return array<int, string>
     */
    private function queriesFor(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        // Nothing is detached: the listener is bound to this test's container, which
        // is thrown away with it.
        return $queries;
    }
}
