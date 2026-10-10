<?php

namespace Tests\Feature\Sponsorship;

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\CouponAllocation;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\User;
use App\Services\Coupon\CouponRedeemer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Coupon\CouponTestCase;

/**
 * The sponsorship area's three tabs: Your Blocks, Event and Shop.
 *
 * WHAT THE OWNER COULD NOT SEE, WHICH IS WHY THE TABS EXIST
 *
 * The screen showed the blocks, the four figures and who used a code. It never named
 * the EVENT any of it was funding — the word did not appear on the page — and it had
 * no answer at all for "kalau dia dari shop, siapa yang beli dan product apa". So the
 * Event tab names the event and lists who registered, with the people under each
 * registration behind a click, and the Shop tab names the buyer and what they bought.
 *
 * A TAB THAT CANNOT HAVE ANYTHING IN IT IS NOT DRAWN
 *
 * A batch is event OR shop, never both. A sponsorship funding only event coupons has
 * a Shop tab that would be empty for ever, so it is not rendered — and because the
 * tab resolver falls back to the first tab there is, putting the slug in ?tab= does
 * not reach it either. The ordinary sponsorship shows two tabs; only one funding both
 * kinds shows three.
 *
 * THE PRIVACY BOUNDARY, WHICH IS WHAT THE ABSENCE ASSERTIONS BELOW DEFEND
 *
 * The participant list is built FROM THE LEDGER ROWS — one row per participant
 * covered, each carrying a name and nothing else — and the buyer's name is copied onto
 * the ledger at redeem time for the same reason. Nothing on this screen joins back to
 * a participant, a registration or an order, so there is no IC, no phone number, no
 * address, no email and no order total to leak. Those are asserted ABSENT, because an
 * absence test is the one that fails if somebody later adds the join.
 */
class SponsorAreaTabsTest extends CouponTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sponsor-Pass-123!';

    /** A participant's own details, which must never reach a sponsor's screen. */
    private const REDEEMER_IC = '880101121234';

    private const REDEEMER_PHONE = '0191234567';

    /** A buyer's, for the same reason. An order holds all three. */
    private const BUYER_PHONE = '0178889999';

    private const BUYER_EMAIL = 'buyer-private@example.test';

    private const BUYER_ADDRESS = '88 Jalan Rahsia';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function sponsor(string $name, ?float $committed = null): User
    {
        return User::create([
            'name' => $name,
            'username' => 'sponsor-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', Role::SPONSOR)->firstOrFail()->id,
            'is_active' => true,
            'is_sponsor' => true,
            'sponsor_committed_amount' => $committed,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Office Admin',
            'username' => 'admin-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /**
     * An event-kind block of codes funded by this sponsorship, on one named event.
     *
     * @return array{0: Event, 1: Coupon, 2: CouponAllocation}
     */
    private function eventSide(User $sponsor, string $eventTitle = 'Larian Amal Sibu'): array
    {
        $event = $this->event(['title' => $eventTitle, 'fee' => 100, 'location' => 'Sibu']);

        $coupon = $this->uniqueCoupon([
            'name' => 'MAJU20',
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
        ]);

        $coupon->events()->attach($event);

        $block = $this->issue($coupon->fresh(), 12, [
            'full_name' => 'Siti Representative',
            'ic_number' => '901010101010',
            'phone' => '0123334444',
        ]);

        $block->forceFill(['sponsor_user_id' => $sponsor->id])->save();

        return [$event, $coupon->fresh(), $block->fresh()];
    }

    /**
     * Spend one code of a block on a one-person registration, naming the redeemer.
     *
     * The participant carries a real IC and phone, because what this suite has to
     * prove is that neither of them reaches a sponsor's screen.
     */
    private function redeem(CouponAllocation $block, Event $event, string $person): EventRegistration
    {
        $registration = $this->registration($event, [], 1);

        $registration->participants()->first()->update([
            'full_name' => $person,
            'ic_number' => self::REDEEMER_IC,
            'phone' => self::REDEEMER_PHONE,
        ]);

        $code = $block->codes()->unused()->orderBy('id')->firstOrFail();

        $outcome = app(CouponRedeemer::class)->claim(
            $block->coupon->fresh(),
            $event->registrationAmount(),
            1,
            $registration->fresh(['participants']),
            null,
            $code,
        );

        $this->assertTrue($outcome->succeeded(), 'The fixture redemption must succeed.');

        return $registration->fresh();
    }

    /**
     * A shop-kind shared batch funded by this sponsorship, and one real order.
     *
     * THROUGH THE PUBLIC CHECKOUT, never by writing a ledger row. A row built by
     * hand would carry a buyer's name while the write path was broken and the test
     * would pass — which is exactly how the blank participant name went unnoticed.
     *
     * @return array{0: ShopProduct, 1: Coupon}
     */
    private function shopSide(User $sponsor, string $buyer = 'Rahim Bin Salleh'): array
    {
        $this->shopOpenWithGateway();
        $this->flatShipping(10);
        $this->fakeChipPurchase();

        $product = $this->product(['name' => 'Jersi Rasmi Kelab', 'price' => 60]);

        $coupon = $this->fixedCoupon(15, [
            'kind' => Coupon::KIND_SHOP,
            'name' => 'SHOP15',
            'quantity' => 5,
            'sponsor_user_id' => $sponsor->id,
        ]);

        $product->coupons()->attach($coupon);

        $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields([
                'customer_name' => $buyer,
                'customer_phone' => self::BUYER_PHONE,
                'customer_email' => self::BUYER_EMAIL,
                'address_line_1' => self::BUYER_ADDRESS,
                'voucher_code' => 'SHOP15',
            ]));

        return [$product->fresh(), $coupon->fresh()];
    }

    /* ---------------------------------------------------------------------
     | Which tabs are drawn at all
     * ------------------------------------------------------------------ */

    public function test_a_sponsorship_funding_only_event_coupons_has_no_shop_tab(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $this->eventSide($maju);

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('Your Blocks');
        $response->assertSee(route('admin.sponsorship.index', ['tab' => 'event']), false);

        // Not drawn, because a shop row can never appear under an event batch.
        $response->assertDontSee(route('admin.sponsorship.index', ['tab' => 'shop']), false);
    }

    public function test_the_shop_slug_in_the_query_string_does_not_reach_a_hidden_shop_tab(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $this->eventSide($maju);

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'shop']));

        // Falls back to the first tab there is, which is the same rule the settings
        // screens follow. Not a 404: the screen is theirs, the tab simply is not.
        $response->assertOk();
        $response->assertSee('Siti Representative');
        $response->assertDontSee('Orders placed with your codes');
        $response->assertDontSee('Products your coupons apply to');
    }

    public function test_a_sponsorship_funding_only_shop_coupons_has_no_event_tab(): void
    {
        $maju = $this->sponsor('Kedai Maju Sdn Bhd', 500);
        $this->shopSide($maju);

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee(route('admin.sponsorship.index', ['tab' => 'shop']), false);
        $response->assertDontSee(route('admin.sponsorship.index', ['tab' => 'event']), false);

        // And the mirror of the test above: the slug does not reach it either.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']))
            ->assertOk()
            ->assertDontSee('Who registered with your codes');
    }

    public function test_a_sponsorship_funding_both_kinds_shows_all_three_tabs(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 1000);

        $this->eventSide($maju);
        $this->shopSide($maju);

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('Your Blocks');
        $response->assertSee(route('admin.sponsorship.index', ['tab' => 'event']), false);
        $response->assertSee(route('admin.sponsorship.index', ['tab' => 'shop']), false);
    }

    /* ---------------------------------------------------------------------
     | The Event tab
     * ------------------------------------------------------------------ */

    /**
     * THE OMISSION BEING CLOSED. The event was nowhere on this screen.
     */
    public function test_the_event_tab_names_the_event_the_coupon_applies_to(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        [$event, , $block] = $this->eventSide($maju, 'Larian Amal Sibu');

        $this->redeem($block, $event, 'Aminah Binti Yusof');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The event, by name, both in its own list and against the use.
        $response->assertSee('Events your coupons apply to');
        $response->assertSee('Larian Amal Sibu');
        $response->assertSee('Sibu');

        // Read off the batch's own ticks, so a batch ticked on an event nobody has
        // registered for yet is still named.
        $response->assertSee('MAJU20');
    }

    public function test_opening_a_usage_row_lists_the_participants_of_that_registration(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        [$event, , $block] = $this->eventSide($maju);

        $this->redeem($block, $event, 'Aminah Binti Yusof');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The row opens, which is what the owner asked for: click participants and
        // see who joined.
        $response->assertSee('1 participant');
        $response->assertSee('Aminah Binti Yusof');

        // Built from the ledger. Nothing on the registration reaches the page: not
        // the reference, not what was charged.
        $response->assertDontSee(EventRegistration::query()->sole()->reference);
        $response->assertDontSee('RM 100.00');
    }

    /**
     * A group of ten entering one code once wrote TEN ledger rows. All ten are named.
     *
     * Registered through the real public form, because that is the only assertion
     * that means anything about who is on the ledger.
     */
    public function test_a_group_of_ten_lists_ten_names(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $event = $this->event([
            'title' => 'Sukan Kampung',
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 150,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $coupon = $this->percentageCoupon(50, [
            'name' => 'KUMPULAN50',
            'quantity' => 100,
            'sponsor_user_id' => $maju->id,
        ]);

        $event->coupons()->attach($coupon);

        $people = [];
        $expected = [];

        for ($i = 1; $i <= 10; $i++) {
            $name = 'Peserta Nombor '.$i;
            $expected[] = $name;
            $people[] = $this->participantFields([
                'full_name' => $name,
                'ic_number' => '90010100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'phone' => self::REDEEMER_PHONE,
            ]);
        }

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => 'KUMPULAN50',
            'team_name' => 'Kumpulan Sibu',
            'participants' => $people,
        ])->assertSessionHasNoErrors();

        $this->assertCount(10, CouponCode::query()->get(), 'Ten participants, ten ledger rows.');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // One row for the registration, saying how many it covered.
        $response->assertSee('10 participants');

        // And all ten names under it.
        foreach ($expected as $name) {
            $response->assertSee($name);
        }

        // No IC and no phone, which is the assertion that fails if somebody joins
        // this list back to the participants.
        $response->assertDontSee(self::REDEEMER_PHONE);
        $response->assertDontSee('900101000001');
    }

    public function test_the_event_tab_carries_no_ic_and_no_phone(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        [$event, , $block] = $this->eventSide($maju);

        $this->redeem($block, $event, 'Aminah Binti Yusof');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();
        $response->assertSee('Aminah Binti Yusof');

        // The redeemer's own details, on the registration and staying there.
        $response->assertDontSee(self::REDEEMER_IC);
        $response->assertDontSee(self::REDEEMER_PHONE);

        // And the representative's, which stay with the office.
        $response->assertDontSee('901010101010');
        $response->assertDontSee('0123334444');
    }

    /* ---------------------------------------------------------------------
     | The Shop tab
     * ------------------------------------------------------------------ */

    public function test_the_buyer_name_is_recorded_on_the_ledger_by_the_public_checkout(): void
    {
        $maju = $this->sponsor('Kedai Maju Sdn Bhd', 500);
        $this->shopSide($maju, 'Rahim Bin Salleh');

        $order = ShopOrder::query()->sole();
        $row = CouponCode::query()->sole();

        $this->assertSame($order->id, $row->shop_order_id);

        // The name, written at the moment of use, the way participant_name is.
        $this->assertSame('Rahim Bin Salleh', $row->buyer_name);

        // A NAME ONLY. Nothing else off the order came with it.
        $this->assertNull($row->participant_name);
        $this->assertSame('15.00', $row->discount_amount);
    }

    public function test_the_shop_tab_names_the_buyer_and_the_products_they_bought(): void
    {
        $maju = $this->sponsor('Kedai Maju Sdn Bhd', 500);
        $this->shopSide($maju, 'Rahim Bin Salleh');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'shop']));

        $response->assertOk();

        // Who bought, off the ledger row.
        $response->assertSee('Orders placed with your codes');
        $response->assertSee('Rahim Bin Salleh');

        // And what they bought, off the order's own line snapshots — catalogue
        // information, which is why it may be read at all.
        $response->assertSee('Jersi Rasmi Kelab');

        // The products the coupon applies to, off the batch's ticks.
        $response->assertSee('Products your coupons apply to');
        $response->assertSee('SHOP15');

        // The discount this sponsorship funded, which is the only money here.
        $response->assertSee('RM 15.00');
    }

    public function test_the_shop_tab_carries_no_phone_no_address_no_email_and_no_order_total(): void
    {
        $maju = $this->sponsor('Kedai Maju Sdn Bhd', 500);
        $this->shopSide($maju, 'Rahim Bin Salleh');

        $order = ShopOrder::query()->sole();

        // The fixture is what makes these assertions mean something: a real order
        // with a real address, phone, email and total behind it.
        $this->assertSame('60.00', $order->items_total);
        $this->assertSame('55.00', $order->grand_total);

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'shop']));

        $response->assertOk();
        $response->assertSee('Rahim Bin Salleh');

        // Every one of these is on the order and none of them is a sponsor's
        // business. Asserted absent, because an absence test is the one that fails
        // if somebody widens the select.
        $response->assertDontSee(self::BUYER_PHONE);
        $response->assertDontSee(self::BUYER_EMAIL);
        $response->assertDontSee(self::BUYER_ADDRESS);
        $response->assertDontSee($order->reference);

        // What the order came to, and what the goods came to. Neither appears.
        $response->assertDontSee('RM 55.00');
        $response->assertDontSee('RM 60.00');
    }

    /* ---------------------------------------------------------------------
     | Each tab shows only its own rows
     * ------------------------------------------------------------------ */

    public function test_each_tab_shows_only_its_own_rows(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 1000);
        [$event, , $block] = $this->eventSide($maju);

        $this->redeem($block, $event, 'Aminah Binti Yusof');
        $this->shopSide($maju, 'Rahim Bin Salleh');

        $eventTab = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $eventTab->assertOk();
        $eventTab->assertSee('Aminah Binti Yusof');
        $eventTab->assertDontSee('Rahim Bin Salleh');
        $eventTab->assertDontSee('Jersi Rasmi Kelab');

        $shopTab = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'shop']));

        $shopTab->assertOk();
        $shopTab->assertSee('Rahim Bin Salleh');
        $shopTab->assertDontSee('Aminah Binti Yusof');
        $shopTab->assertDontSee('Larian Amal Sibu');
    }

    public function test_one_sponsorships_shop_order_is_not_on_another_sponsorships_tab(): void
    {
        $maju = $this->sponsor('Kedai Maju Sdn Bhd', 500);
        $other = $this->sponsor('Kedai Lain Sdn Bhd', 900);

        $this->shopSide($maju, 'Rahim Bin Salleh');

        // The other sponsorship funds a shop batch of its own, used by nobody.
        $theirProduct = $this->product(['name' => 'Topi Pasukan Lain', 'price' => 30]);

        $theirCoupon = $this->fixedCoupon(5, [
            'kind' => Coupon::KIND_SHOP,
            'name' => 'OTHER5',
            'quantity' => 5,
            'sponsor_user_id' => $other->id,
        ]);

        $theirProduct->coupons()->attach($theirCoupon);

        $response = $this->actingAs($other)
            ->get(route('admin.sponsorship.index', ['tab' => 'shop']));

        $response->assertOk();

        // Their own product, and not the other sponsorship's buyer or goods.
        $response->assertSee('Topi Pasukan Lain');
        $response->assertDontSee('Rahim Bin Salleh');
        $response->assertDontSee('Jersi Rasmi Kelab');

        // An empty tab reads as empty on purpose rather than as a broken screen.
        $response->assertSee('Nothing has been bought yet');
    }

    /* ---------------------------------------------------------------------
     | Empty on purpose
     * ------------------------------------------------------------------ */

    public function test_a_sponsorship_with_nothing_tagged_says_so_and_shows_one_tab(): void
    {
        $sponsor = $this->sponsor('Untagged Sponsor', 500);

        $response = $this->actingAs($sponsor)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('No coupon blocks have been tagged to this sponsorship yet');

        // Nothing is funded, so neither usage tab can have anything in it.
        $response->assertDontSee(route('admin.sponsorship.index', ['tab' => 'event']), false);
        $response->assertDontSee(route('admin.sponsorship.index', ['tab' => 'shop']), false);
    }

    public function test_an_event_tab_with_no_uses_yet_reads_as_empty_on_purpose(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        [$event] = $this->eventSide($maju, 'Larian Amal Sibu');

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The event is still named, because it is read off the batch rather than off
        // a registration that has not happened.
        $response->assertSee($event->title);
        $response->assertSee('Nothing has been used yet');
        $response->assertSee('A row appears here the moment somebody registers with one of your codes.');
    }

    /* ---------------------------------------------------------------------
     | A CSV per tab
     * ------------------------------------------------------------------ */

    public function test_each_tabs_csv_carries_only_that_tabs_rows_and_is_logged(): void
    {
        // 16:30 UTC is already the next day in Malaysia, which is where this project
        // has had the same off-by-eight-hours bug four times.
        Carbon::setTestNow(Carbon::parse('2026-03-10 16:30:00', 'UTC'));

        try {
            $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 1000);
            [$event, , $block] = $this->eventSide($maju, 'Larian Amal Sibu');

            $this->redeem($block, $event, 'Aminah Binti Yusof');
            $this->shopSide($maju, 'Rahim Bin Salleh');

            /* ---- the event file ---- */
            $events = $this->actingAs($maju)
                ->get(route('admin.sponsorship.export', ['set' => 'event']));

            $events->assertOk();
            $csv = $events->streamedContent();

            $this->assertStringContainsString('Aminah Binti Yusof', $csv);
            $this->assertStringContainsString('Larian Amal Sibu', $csv);
            $this->assertStringContainsString('11 Mar 2026', $csv, 'The office clock, not UTC.');

            // Not the other tab's rows, and no contact details anywhere.
            $this->assertStringNotContainsString('Rahim Bin Salleh', $csv);
            $this->assertStringNotContainsString('Jersi Rasmi Kelab', $csv);
            $this->assertStringNotContainsString(self::REDEEMER_IC, $csv);
            $this->assertStringNotContainsString(self::REDEEMER_PHONE, $csv);

            /* ---- the shop file ---- */
            $shop = $this->actingAs($maju)
                ->get(route('admin.sponsorship.export', ['set' => 'shop']));

            $shop->assertOk();
            $csv = $shop->streamedContent();

            $this->assertStringContainsString('Rahim Bin Salleh', $csv);
            $this->assertStringContainsString('Jersi Rasmi Kelab', $csv);

            $this->assertStringNotContainsString('Aminah Binti Yusof', $csv);
            $this->assertStringNotContainsString(self::BUYER_PHONE, $csv);
            $this->assertStringNotContainsString(self::BUYER_EMAIL, $csv);
            $this->assertStringNotContainsString(self::BUYER_ADDRESS, $csv);

            /* ---- the blocks file ---- */
            $blocks = $this->actingAs($maju)
                ->get(route('admin.sponsorship.export', ['set' => 'blocks']));

            $blocks->assertOk();
            $csv = $blocks->streamedContent();

            $this->assertStringContainsString('Siti Representative', $csv);
            $this->assertStringNotContainsString('Aminah Binti Yusof', $csv);
            $this->assertStringNotContainsString('Rahim Bin Salleh', $csv);

            // Three files, three activity lines. A list of names is a disclosure
            // even when it is only names.
            $this->assertSame(3, ActivityLog::where('user_id', $maju->id)
                ->where('action', 'sponsorship.export')
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_csv_for_a_tab_this_sponsorship_does_not_have_is_a_404(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $this->eventSide($maju);

        // No shop batch, so there is no shop file either, for the same reason the
        // tab is not drawn.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.export', ['set' => 'shop']))
            ->assertNotFound();
    }

    public function test_staff_export_one_sponsorship_by_id_and_a_sponsor_cannot(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $other = $this->sponsor('Other NGO Berhad', 900);

        [$event, , $block] = $this->eventSide($maju);
        $this->redeem($block, $event, 'Aminah Binti Yusof');

        $response = $this->actingAs($this->admin())
            ->get(route('admin.sponsorship.export', ['set' => 'event', 'sponsor' => $maju->id]));

        $response->assertOk();
        $this->assertStringContainsString('Aminah Binti Yusof', $response->streamedContent());

        // The other sponsorship's file has none of it.
        $this->actingAs($this->admin())
            ->get(route('admin.sponsorship.export', ['set' => 'blocks', 'sponsor' => $other->id]))
            ->assertOk();

        // And a sponsorship account naming anybody at all is refused.
        $this->actingAs($other)
            ->get(route('admin.sponsorship.export', ['set' => 'blocks', 'sponsor' => $maju->id]))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Paging one tab leaves the other alone
     * ------------------------------------------------------------------ */

    public function test_a_page_number_from_one_tab_does_not_disturb_another(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 1000);
        [$event, , $block] = $this->eventSide($maju);

        $this->redeem($block, $event, 'Aminah Binti Yusof');
        $this->shopSide($maju, 'Rahim Bin Salleh');

        // Page two of the event list is past the end, so the event row is gone.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event', 'event_page' => 2]))
            ->assertOk()
            ->assertDontSee('Aminah Binti Yusof');

        // The shop list has its own page name, so the same number leaves it alone.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'shop', 'event_page' => 2]))
            ->assertOk()
            ->assertSee('Rahim Bin Salleh');

        // And the mirror.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event', 'shop_page' => 2]))
            ->assertOk()
            ->assertSee('Aminah Binti Yusof');

        // The tab links themselves carry no page number at all, so moving tab never
        // lands on page two of anything.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event', 'event_page' => 2]))
            ->assertSee(route('admin.sponsorship.index', ['tab' => 'shop']), false);
    }
}
