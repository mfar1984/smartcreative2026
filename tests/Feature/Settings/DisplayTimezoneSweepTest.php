<?php

namespace Tests\Feature\Settings;

use App\Mail\ContactEnquiryReceived;
use App\Mail\PlayerEnquiryReceived;
use App\Mail\ShopOrderBankTransferInstructions;
use App\Mail\ShopOrderCollectionReady;
use App\Mail\ShopOrderPaymentLink;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Permission;
use App\Models\PlayerMessage;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\User;
use App\Support\EventTemplates;
use App\Support\GeneralSettings;
use App\Support\LocalTime;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Timezone field reaches the screens and the emails it did not reach before.
 *
 * App\Support\LocalTime was already the single conversion point, and the General
 * Config screen already wrote the zone it reads. What was missing was reach:
 * thirteen files called the helper and two dozen other places called ->format()
 * straight off the attribute, so the same instant read 8:51 am on the
 * participants list and 12:51 am on the Gateway Receipts screen beside it.
 *
 * The instant below is chosen to straddle midnight UTC. 00:51 UTC is 8:51 in
 * Kuala Lumpur, so the hour, the am/pm and nothing else move; a conversion that
 * quietly did nothing cannot pass here by coincidence.
 *
 * WHAT IS DELIBERATELY NOT CONVERTED
 *
 * A wall-clock value a person typed is not a UTC instant and must not be shifted.
 * shop_orders.collection_at is the clearest case: it comes off a datetime-local
 * box and is stored exactly as written, so running it through the helper would
 * move a collection appointment by eight hours. test_a_typed_wall_clock_value_is
 * _never_shifted holds that line, and it is the assertion that fails if somebody
 * later "tidies up" the remaining ->format() calls without reading them.
 */
class DisplayTimezoneSweepTest extends TestCase
{
    use RefreshDatabase;

    /** The stored value, in UTC, exactly as every column holds it. */
    private const STORED = '2026-10-02 00:51:00';

    /** The same instant in Kuala Lumpur, in the shape most screens use. */
    private const SHOWN = '02 Oct 2026, 8:51 am';

    /** Its date half, for the screens that stack date over time. */
    private const SHOWN_DATE = '02 Oct 2026';

    /** Its time half. */
    private const SHOWN_TIME = '8:51 am';

    /** The log screens carry seconds. */
    private const SHOWN_SECONDS = '8:51:00 am';

    /** What every one of them used to say, and must not say again. */
    private const WAS_SHOWN = '02 Oct 2026, 12:51 am';

    /** Its time half, which is the part that moves. */
    private const WAS_SHOWN_TIME = '12:51 am';

    /** The collection appointment as a member of staff typed it into the form. */
    private const TYPED_COLLECTION = '2026-10-10 09:30:00';

    /** Read back as typed, which is the only reading that is right. */
    private const TYPED_COLLECTION_DAY = 'Saturday, 10 October 2026';

    private const TYPED_COLLECTION_TIME = '9:30 am';

    /** What the appointment would read as if it were wrongly converted. */
    private const TYPED_COLLECTION_SHIFTED = '5:30 pm';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        /*
         | Written as a settings row rather than left to config, because the row is
         | the thing under test: the field on the General Config screen is what the
         | owner edits, and reading config would pass even if the setting were
         | ignored again. flush() because GeneralSettings memoises per process and
         | the container booted before this line ran.
         */
        Setting::write('general.timezone', 'Asia/Kuala_Lumpur', 'general');
        GeneralSettings::flush();
    }

    /* ---------------------------------------------------------------------
     | People
     * ------------------------------------------------------------------ */

    /**
     * A user holding exactly the named permissions, and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, so a
     * screen that is reached here is reached the way a member of staff reaches it.
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
                ['name' => $slug, 'group' => 'Event', 'module' => 'Participants', 'action' => 'view', 'sort_order' => $index],
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

    /* ---------------------------------------------------------------------
     | Rows
     * ------------------------------------------------------------------ */

    private function event(): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Hari Sukan Negara',
            'category' => 'Community',
            'starts_at' => '2026-10-17',
            'ends_at' => '2026-10-17',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 40,
            'seats_total' => 100,
        ]);
    }

    /**
     * One entry with one person on it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function registration(Event $event, array $overrides = []): EventRegistration
    {
        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'mode' => Event::MODE_INDIVIDUAL,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 40,
            'amount' => 40,
            'amount_paid' => 40,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Aminah Yusof',
            'ic_number' => '9001' . str_pad((string) $registration->id, 8, '0', STR_PAD_LEFT),
            'phone' => '0142000111',
            'email' => 'aminah-' . $registration->id . '@example.com',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        return $registration->fresh('participants');
    }

    /**
     * An entry whose gateway rows contradict the gateway's own payload.
     *
     * The Gateway Receipts screen lists nothing else: GatewayReceiptAudit keeps only
     * source=gateway rows carrying no member of staff and no slip, against a stored
     * payload that says the purchase took less than the rows add up to. That filter
     * is exactly why received_at is a genuine UTC instant on that screen and may be
     * converted, while the mixed ledger on the entry screen may not.
     */
    private function contradictedEntry(Event $event): EventRegistration
    {
        $purchase = 'purchase-' . uniqid();

        $registration = $this->registration($event, [
            'payment_reference' => $purchase,
            'amount' => 80,
            'amount_paid' => 80,
        ]);

        $registration->payment_details = [
            'id' => $purchase,
            'reference' => $registration->reference,
            'status' => 'paid',
            'purchase' => ['total' => 4000, 'currency' => 'MYR'],
            'payment' => ['amount' => 4000, 'currency' => 'MYR'],
        ];
        $registration->payment_synced_at = Carbon::parse(self::STORED, 'UTC');
        $registration->save();

        /*
         | The genuine row first, which fits inside what the payload reports and is
         | therefore kept. Given a plainly different clock time so it cannot satisfy
         | an assertion meant for the row below.
         */
        $this->gatewayReceipt($registration, 40, '2026-10-04 11:47:41', $purchase);

        /*
         | And the fabricated one, which is the row the screen lists: findingsFor()
         | walks the purchase oldest first, keeps rows while they still fit inside
         | CHIP's figure, and reports the overflow. So the assertions read this
         | received_at, and it carries the instant under test.
         */
        $this->gatewayReceipt($registration, 40, self::STORED, $purchase);

        return $registration->fresh('payments');
    }

    /**
     * A website enquiry carrying the instant under test, never saved.
     *
     * Not persisted on purpose: contact_messages has no migration in this
     * repository, so the test database has no such table even though production
     * does. The mailable reads the attribute and nothing else, and render() does
     * not serialise the model, so an in-memory row exercises the template exactly
     * as a queued send would.
     */
    private function contactMessage(): ContactMessage
    {
        $message = new ContactMessage([
            'name' => 'Aminah Yusof',
            'email' => 'aminah@example.com',
            'phone' => '0123456789',
            'service' => 'event-management',
            'message' => 'Please call me back.',
        ]);

        $message->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')]);

        return $message;
    }

    private function gatewayReceipt(
        EventRegistration $registration,
        float $amount,
        string $receivedAt,
        string $reference,
    ): EventRegistrationPayment {
        return EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => $receivedAt,
            'reference' => $reference,
            'note' => 'Taken by the payment gateway.',
            'source' => EventRegistrationPayment::SOURCE_GATEWAY,
        ]);
    }

    /**
     * An order as the checkout would have written it.
     *
     * created_at is forced after the insert because it is not fillable, and
     * collection_at is given a round local time on a different day so the two
     * buckets cannot be confused for one another in an assertion.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopOrder
    {
        $order = ShopOrder::create($overrides + [
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
            'fulfilment' => ShopOrder::FULFILMENT_OFFLINE,
            'payment_method' => ShopOrder::METHOD_GATEWAY,

            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-' . uniqid() . '@example.com',
            'customer_phone' => '0123456789',
            'identity_card' => '900101071234',

            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',

            'items_total' => 25.00,
            'shipping_total' => 0,
            'grand_total' => 25.00,
            'shipping_label' => 'Collected at the counter, nothing posted',

            'collection_label' => 'Collection point',
            'collection_location' => 'Dewan Serbaguna, Shah Alam',
            'collection_at' => self::TYPED_COLLECTION,
        ]);

        $order->items()->create([
            'name' => 'Team Jersey',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'unit_price' => 25.00,
            'quantity' => 1,
            'line_total' => 25.00,
            'weight_grams' => 300,
        ]);

        $order->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();

        return $order->fresh('items');
    }

    /* =====================================================================
     | The helper, and the shape of the whole change
     * ================================================================== */

    public function test_the_saved_zone_drives_the_helper_and_storage_stays_utc(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', LocalTime::zone());
        $this->assertSame('UTC', config('app.timezone'));

        $moment = Carbon::parse(self::STORED, 'UTC');

        $this->assertSame(self::SHOWN, LocalTime::format($moment));

        // The clone inside the helper is load-bearing: Carbon 3 instances are
        // mutable, so a helper that shifted in place would move the attribute on
        // the model it was handed.
        $this->assertSame(self::STORED, $moment->toDateTimeString());
        $this->assertSame('UTC', $moment->getTimezone()->getName());
    }

    public function test_the_screens_follow_the_saved_zone_and_not_the_config_default(): void
    {
        /*
         | Asia/Kuching is also +8, so a zone that merely happens to agree would
         | hide a screen still reading config. Pacific/Honolulu is -10, which puts
         | the same instant on the day before and proves the row is what is read.
         */
        Setting::write('general.timezone', 'Pacific/Honolulu', 'general');
        GeneralSettings::flush();

        $event = $this->event();
        $this->contradictedEntry($event);

        $response = $this->actingAs($this->userWith(['participants.view', 'payments.record']))
            ->get(route('admin.event.participants.receipts', $event));

        $response->assertOk();
        $response->assertSee('01 Oct 2026');
        $response->assertSee('2:51 pm');
        $response->assertDontSee(self::SHOWN_TIME);
    }

    /* =====================================================================
     | Screen by screen
     * ================================================================== */

    public function test_the_gateway_receipts_screen_reads_the_office_clock(): void
    {
        $event = $this->event();
        $registration = $this->contradictedEntry($event);

        $response = $this->actingAs($this->userWith(['participants.view', 'payments.record']))
            ->get(route('admin.event.participants.receipts', $event));

        $response->assertOk();
        $response->assertSee($registration->reference);
        $response->assertSee(self::SHOWN_DATE);
        $response->assertSee(self::SHOWN_TIME);
        $response->assertDontSee(self::WAS_SHOWN_TIME);
    }

    public function test_the_audit_log_screen_reads_the_office_clock(): void
    {
        $user = $this->userWith(['logs.activity.view', 'logs.audit.view']);

        $activity = ActivityLog::create([
            'user_id' => $user->id,
            'actor_label' => 'Staff',
            'action' => 'settings.general.updated',
            'level' => 'info',
            'category' => 'Settings',
            'description' => 'Saved the general configuration.',
            'ip_address' => '203.0.113.9',
        ]);

        $audit = AuditLog::create([
            'user_id' => $user->id,
            'actor_label' => 'Staff',
            'actor_role' => 'Super Admin',
            'auditable_type' => Setting::class,
            'auditable_id' => 1,
            'event' => 'updated',
            'ip_address' => '203.0.113.9',
        ]);

        foreach ([$activity, $audit] as $entry) {
            $entry->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();
        }

        foreach (['activity', 'audit'] as $tab) {
            $response = $this->actingAs($user)
                ->get(route('admin.settings.logging', ['tab' => $tab]));

            $response->assertOk();
            $response->assertSee(self::SHOWN_DATE);
            $response->assertSee(self::SHOWN_SECONDS);
            $response->assertDontSee('12:51:00 am');
        }
    }

    public function test_a_campaign_screen_reads_the_office_clock(): void
    {
        Campaign::create([
            'name' => 'Reminder blast',
            'channel' => EventTemplates::CHANNEL_EMAIL,
            'subject' => 'Your entry',
            'body' => 'Hello.',
            'audience_type' => 'all',
            'status' => Campaign::STATUS_SENT,
            'recipients_total' => 1,
            'sent_count' => 1,
            'started_at' => Carbon::parse(self::STORED, 'UTC'),
        ]);

        $response = $this->actingAs($this->userWith(['campaigns.view']))
            ->get(route('admin.campaigns.index'));

        $response->assertOk();
        $response->assertSee('Reminder blast');
        $response->assertSee(self::SHOWN);
        $response->assertDontSee(self::WAS_SHOWN);
    }

    public function test_a_payment_screen_reads_the_office_clock(): void
    {
        $registration = $this->registration($this->event());

        $registration->forceFill(['payment_synced_at' => Carbon::parse(self::STORED, 'UTC')])->save();

        $response = $this->actingAs($this->userWith(['payments.view']))
            ->get(route('admin.payments.overview'));

        $response->assertOk();
        $response->assertSee($registration->reference);
        $response->assertSee(self::SHOWN);
        $response->assertDontSee(self::WAS_SHOWN);
    }

    public function test_an_attendance_partial_reads_the_office_clock(): void
    {
        $event = $this->event();
        $registration = $this->registration($event);
        $participant = $registration->participants->first();

        EventAttendance::create([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            'event_participant_id' => $participant->id,
            'checked_in_at' => Carbon::parse(self::STORED, 'UTC'),
            'ic_verified' => true,
        ]);

        $response = $this->actingAs($this->userWith(['attendance.view']))
            ->get(route('admin.event.attendance', ['tab' => 'present']));

        $response->assertOk();
        $response->assertSee($registration->reference);
        $response->assertSee(self::SHOWN);
        $response->assertDontSee(self::WAS_SHOWN);
    }

    /* =====================================================================
     | The bug: one instant, two screens, two answers
     * ================================================================== */

    public function test_one_instant_reads_the_same_on_two_different_screens(): void
    {
        /*
         | This is the whole complaint. The gateway's own instant was converted on
         | the participants list and left raw on the Gateway Receipts screen beside
         | it, so two pages describing the same payment disagreed by eight hours.
         | Both are asserted from the same stored value, so the pair cannot drift
         | apart again without this failing.
         */
        $event = $this->event();
        $registration = $this->contradictedEntry($event);

        $registration->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();

        $staff = $this->userWith(['participants.view', 'payments.record']);

        $list = $this->actingAs($staff)->get(route('admin.event.participants'));
        $receipts = $this->actingAs($staff)->get(route('admin.event.participants.receipts', $event));

        $list->assertOk();
        $receipts->assertOk();

        foreach ([$list, $receipts] as $response) {
            $response->assertSee(self::SHOWN_DATE);
            $response->assertSee(self::SHOWN_TIME);
            $response->assertDontSee(self::WAS_SHOWN_TIME);
        }
    }

    /* =====================================================================
     | The five emails, which leave the building
     * ================================================================== */

    public function test_the_contact_enquiry_email_renders_the_converted_time(): void
    {
        $html = (new ContactEnquiryReceived($this->contactMessage()))->render();

        $this->assertStringContainsString('Received ' . self::SHOWN, $html);
        $this->assertStringNotContainsString(self::WAS_SHOWN, $html);
    }

    public function test_the_player_enquiry_email_renders_the_converted_time(): void
    {
        $registration = $this->registration($this->event());
        $participant = $registration->participants->first();

        $message = PlayerMessage::create([
            'event_participant_id' => $participant->id,
            'name' => 'A supporter',
            'email' => 'supporter@example.com',
            'phone' => '0123456789',
            'message' => 'Well played.',
        ]);

        $message->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();

        $html = (new PlayerEnquiryReceived(
            $message->fresh(),
            $participant,
            'A. Yusof',
        ))->render();

        $this->assertStringContainsString('Received ' . self::SHOWN, $html);
        $this->assertStringNotContainsString(self::WAS_SHOWN, $html);
    }

    public function test_the_bank_transfer_email_renders_the_converted_time(): void
    {
        $order = $this->order();

        $html = (new ShopOrderBankTransferInstructions(
            $order,
            ['name' => 'Smart Creative', 'bank' => 'Maybank', 'number' => '512345678901'],
            null,
            'https://example.test/receipt',
        ))->render();

        $this->assertStringContainsString('placed ' . self::SHOWN, $html);
        $this->assertStringNotContainsString(self::WAS_SHOWN, $html);
    }

    public function test_the_payment_link_email_renders_the_converted_time(): void
    {
        $order = $this->order();

        $html = (new ShopOrderPaymentLink($order, 'https://example.test/order'))->render();

        $this->assertStringContainsString('placed ' . self::SHOWN, $html);
        $this->assertStringNotContainsString(self::WAS_SHOWN, $html);
    }

    public function test_the_collection_email_renders_the_converted_time(): void
    {
        $order = $this->order([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => Carbon::parse(self::STORED, 'UTC'),
        ]);

        $html = (new ShopOrderCollectionReady($order))->render();

        $this->assertStringContainsString('paid ' . self::SHOWN, $html);
        $this->assertStringNotContainsString(self::WAS_SHOWN, $html);
    }

    /* =====================================================================
     | The line that must not move
     * ================================================================== */

    public function test_a_typed_wall_clock_value_is_never_shifted(): void
    {
        /*
         | collection_at comes off a datetime-local box and is stored exactly as
         | written, so it is already the counter's own clock. The paid stamp in the
         | same email is a real UTC instant and does move. Both are asserted on one
         | render, because the failure worth catching is a sweep that treats the two
         | as the same kind of value.
         */
        $order = $this->order([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => Carbon::parse(self::STORED, 'UTC'),
        ]);

        $html = (new ShopOrderCollectionReady($order))->render();

        $this->assertStringContainsString(self::TYPED_COLLECTION_DAY, $html);
        $this->assertStringContainsString(self::TYPED_COLLECTION_TIME, $html);
        $this->assertStringNotContainsString(self::TYPED_COLLECTION_SHIFTED, $html);

        // And the converted half of the same email still converts.
        $this->assertStringContainsString('paid ' . self::SHOWN, $html);

        // The admin order screen reads it the same way.
        $response = $this->actingAs($this->userWith(['shop.orders.view']))
            ->get(route('admin.shop.orders.show', $order));

        $response->assertOk();
        $response->assertSee('10 Oct 2026');
        $response->assertSee(self::TYPED_COLLECTION_TIME);
        $response->assertDontSee(self::TYPED_COLLECTION_SHIFTED);
    }

    /* =====================================================================
     | Nothing stored moves
     * ================================================================== */

    public function test_rendering_the_screens_and_the_emails_moves_no_stored_column(): void
    {
        $event = $this->event();
        $registration = $this->contradictedEntry($event);
        $order = $this->order([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => Carbon::parse(self::STORED, 'UTC'),
        ]);

        $staff = $this->userWith(['participants.view', 'payments.record', 'shop.orders.view']);

        $this->actingAs($staff)->get(route('admin.event.participants'))->assertOk();
        $this->actingAs($staff)->get(route('admin.event.participants.receipts', $event))->assertOk();
        $this->actingAs($staff)->get(route('admin.shop.orders.show', $order))->assertOk();

        (new ShopOrderCollectionReady($order->fresh('items')))->render();

        /*
         | Read straight off the columns, past the Eloquent date casts: a cast would
         | parse a shifted string back again and hide exactly the damage being
         | guarded against.
         */
        $receipts = DB::table('event_registration_payments')
            ->where('event_registration_id', $registration->id)
            ->orderBy('id')
            ->pluck('received_at')
            ->map(fn ($value) => (string) $value)
            ->all();

        // The kept row, then the one the screen listed and converted.
        $this->assertStringStartsWith('2026-10-04 11:47:41', $receipts[0]);
        $this->assertStringStartsWith(self::STORED, $receipts[1]);

        $this->assertStringStartsWith(self::STORED, (string) DB::table('event_registrations')
            ->where('id', $registration->id)
            ->value('payment_synced_at'));

        $this->assertStringStartsWith(self::STORED, (string) DB::table('shop_orders')
            ->where('id', $order->id)
            ->value('created_at'));

        $this->assertStringStartsWith(self::STORED, (string) DB::table('shop_orders')
            ->where('id', $order->id)
            ->value('paid_at'));

        $this->assertStringStartsWith(self::TYPED_COLLECTION, (string) DB::table('shop_orders')
            ->where('id', $order->id)
            ->value('collection_at'));
    }

    /* =====================================================================
     | Outside a web request, and with nothing saved
     * ================================================================== */

    public function test_an_unsaved_timezone_falls_through_to_the_config_default(): void
    {
        /*
         | The worker's situation on a fresh installation: no settings row, a cold
         | static cache, and a mailable being rendered with no request behind it.
         | It must fall through to config rather than throw.
         */
        Setting::query()->where('key', 'general.timezone')->delete();
        GeneralSettings::flush();

        $this->assertSame(config('app.display_timezone'), LocalTime::zone());

        $html = (new ContactEnquiryReceived($this->contactMessage()))->render();

        // Asia/Kuala_Lumpur is also the shipped config default, so the fallback
        // lands on the same reading rather than on UTC.
        $this->assertStringContainsString('Received ' . self::SHOWN, $html);
    }

    public function test_a_queued_mailable_reads_the_setting_with_no_request_behind_it(): void
    {
        /*
         | Queued mail renders in the worker process, where there is no request and
         | nothing has warmed the memoised zone. Simulated by flushing the static
         | cache and rendering the mailable directly, which is the same cold path
         | the worker takes: one read of the settings group, then memoised.
         */
        Setting::write('general.timezone', 'Pacific/Honolulu', 'general');
        GeneralSettings::flush();

        $order = $this->order([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => Carbon::parse(self::STORED, 'UTC'),
        ]);

        $html = (new ShopOrderCollectionReady($order))->render();

        $this->assertStringContainsString('paid 01 Oct 2026, 2:51 pm', $html);

        // The typed appointment is still read as typed, whatever the zone is.
        $this->assertStringContainsString(self::TYPED_COLLECTION_DAY, $html);
        $this->assertStringContainsString(self::TYPED_COLLECTION_TIME, $html);
    }

    /* =====================================================================
     | No query per rendered row
     * ================================================================== */

    public function test_rendering_a_longer_list_costs_no_extra_queries(): void
    {
        /*
         | LocalTime::zone() is called once per rendered cell, so the thing worth
         | proving is that it is not a database read per cell. GeneralSettings reads
         | the whole group once per process and memoises the resolved zone, which
         | means a list of twenty rows must cost exactly what a list of one costs.
         |
         | Compared against each other rather than against a hardcoded number: the
         | screen's own joins and pagination counts are not what is being measured,
         | and pinning them would turn this into a maintenance chore.
         */
        $user = $this->userWith(['logs.activity.view']);

        /*
         | Counted against the settings table rather than against the whole log,
         | because that is the question. A screen's own joins, its level counts and
         | its pagination count are not what this change touched and pinning their
         | total would make the assertion a maintenance chore; a second read of
         | `settings` is the failure, and it is unambiguous.
         */
        $this->assertSame(1, $this->settingsReadsRendering($user, 1));
        $this->assertSame(1, $this->settingsReadsRendering($user, 20));
    }

    /**
     * Render the activity log with $rows entries and count the reads of `settings`.
     */
    private function settingsReadsRendering(User $user, int $rows): int
    {
        ActivityLog::query()->delete();

        for ($n = 0; $n < $rows; $n++) {
            ActivityLog::create([
                'user_id' => $user->id,
                'actor_label' => 'Staff',
                'action' => 'settings.general.viewed',
                'level' => 'info',
                'category' => 'Settings',
                'description' => 'Row number ' . $n . '.',
                'ip_address' => '203.0.113.9',
            ])->forceFill(['created_at' => Carbon::parse(self::STORED, 'UTC')])->save();
        }

        // Cleared so the group is resolved inside the measured render on both
        // passes rather than warmed by the writes above on one of them.
        GeneralSettings::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'activity']));

        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains((string) $entry['query'], '"settings"'))
            ->count();

        DB::disableQueryLog();

        $response->assertOk();
        $response->assertSee(self::SHOWN_SECONDS);

        return $reads;
    }
}
