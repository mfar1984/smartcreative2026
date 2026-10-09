<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\ParticipantSizeController;
use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Shop\ShopPaymentTestCase;

/**
 * The signature the reference limiter trusts is on the form a participant submits.
 *
 * public-reference spends a link's ten a minute only when the request carries that
 * link's valid signature, and counts anything else against the sender's session.
 * So the signature has to be on each form's action, not only on the page the form
 * sits on. Each test opens the page a participant opens, reads the action off the
 * form, and posts exactly that.
 *
 * Covers, for the registration Pay button, the shirt size form and the shop Pay Now
 * button: the action is signed and reaches the POST route the limiter is on; ten
 * unsigned posts to the same reference from one browser are refused, and the
 * eleventh is stopped against that browser alone; the holder's ten signed posts,
 * each from a fresh session, all get past the signature check; the eleventh is
 * stopped, which can only happen if the limiter counted the reference, since
 * nothing else is common to those eleven.
 *
 * The size form matters most: it checks its signature in the controller rather than
 * through the signed middleware, and the limiter has to agree with it.
 *
 * Built on the shop payment fixtures for a usable gateway, which is what draws both
 * Pay buttons. Each entry or order is settled after its page is drawn, so pressing
 * Pay is answered from the database and never reaches the gateway. Mail, the queue
 * and outbound HTTP are faked.
 */
class PublicReferenceSignedFormTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const VENUE_IP = '203.0.113.200';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::fake();

        $this->gatewayShopSettings();

        $this->withServerVariables(['REMOTE_ADDR' => self::VENUE_IP]);
    }

    public function test_the_registration_pay_button_posts_a_signed_url_counted_against_its_reference(): void
    {
        $registration = $this->owingRegistration($this->event(), 'REG-2026-0911');

        $action = $this->formAction(
            $this->get(RegistrationPaymentController::urlFor($registration)),
            'registration.payment.pay',
            $registration->reference,
        );

        // Settled once the page is drawn, so pressing Pay is answered from the
        // database rather than by opening a checkout.
        $registration->forceFill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => $registration->amount,
        ])->save();

        $this->assertOnlyTheSignedLinkSpendsItsBudget($action);
    }

    public function test_the_shirt_size_form_posts_a_signed_url_counted_against_its_reference(): void
    {
        $event = $this->event();
        $this->tee($event);

        $registration = $this->owingRegistration($event, 'REG-2026-0912');

        $action = $this->formAction(
            $this->get(ParticipantSizeController::urlFor($registration)),
            'registration.sizes.store',
            $registration->reference,
        );

        $this->assertOnlyTheSignedLinkSpendsItsBudget($action);
    }

    public function test_the_shop_pay_now_button_posts_a_signed_url_counted_against_its_reference(): void
    {
        $order = $this->order();

        $action = $this->formAction(
            $this->get(ShopOrderPaymentController::urlFor($order)),
            'shop.order.pay',
            $order->reference,
        );

        // Settled once the page is drawn, for the same reason as the entry above.
        $order->forceFill(['status' => ShopOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $this->assertOnlyTheSignedLinkSpendsItsBudget($action);
    }

    /* ---------------------------------------------------------------------
     | Assertions
     * ------------------------------------------------------------------ */

    /**
     * The action of the page's form that posts to this route, as a browser submits
     * it, once it is shown to be signed and to reach that POST route.
     */
    private function formAction(TestResponse $page, string $route, string $reference): string
    {
        $page->assertOk();

        preg_match_all('/<form\b[^>]*\baction="([^"]*)"/i', (string) $page->getContent(), $matches);

        $path = parse_url(route($route, ['reference' => $reference]), PHP_URL_PATH);

        // Decoded the way a browser decodes the attribute before submitting it.
        $actions = array_values(array_filter(
            array_map(fn (string $action) => html_entity_decode($action, ENT_QUOTES | ENT_HTML5), $matches[1]),
            fn (string $action) => parse_url($action, PHP_URL_PATH) === $path,
        ));

        $this->assertCount(1, $actions, 'One form on the page posts to ' . $path);

        $submitted = Request::create($actions[0], 'POST');

        $this->assertTrue(URL::hasValidSignature($submitted), 'The form action carries a valid signature.');
        $this->assertSame($route, Route::getRoutes()->match($submitted)->getName());

        return $actions[0];
    }

    /**
     * Unsigned posts to the link's own path from one browser, then the holder's
     * signed posts, each from a browser that has sent nothing before.
     */
    private function assertOnlyTheSignedLinkSpendsItsBudget(string $action): void
    {
        $guessed = Str::before($action, '?');

        // Somebody who has guessed the reference but holds no link: refused by the
        // signature check ten times, then stopped against their own browser.
        $this->asPhone(Str::random(40));

        for ($i = 1; $i <= 10; $i++) {
            $this->post($guessed)->assertForbidden();
        }

        $this->post($guessed)->assertStatus(429);

        // The holder still has all ten, each one past the signature check: a
        // redirect, never the 403 an unsigned post gets.
        for ($i = 1; $i <= 10; $i++) {
            $this->asPhone(Str::random(40))->post($action)->assertRedirect();
        }

        // Only the reference is common to those ten and this one.
        $this->asPhone(Str::random(40))->post($action)->assertStatus(429);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** Every following request carries this session cookie, as one browser would. */
    private function asPhone(string $sessionId): static
    {
        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    private function event(): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Stadium Cup',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 10,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 5,
        ]);
    }

    /** A shirt with sizes, chosen one person at a time, which is what draws the size form. */
    private function tee(Event $event): void
    {
        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'EVENT TEE',
            'price' => 0,
            'is_required' => false,
            'is_active' => true,
            'per_participant' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        foreach (['S', 'M'] as $position => $label) {
            EventAddonVariant::create([
                'event_addon_id' => $addon->id,
                'label' => $label,
                'price' => null,
                'stock' => null,
                'sort_order' => $position + 1,
            ]);
        }
    }

    /** An entry still owing its fee, with one person on it and no size recorded. */
    private function owingRegistration(Event $event, string $reference): EventRegistration
    {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Squad ' . $reference,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 10,
            'addons_total' => 0,
            'amount' => 10,
            'amount_paid' => 0,
        ]);

        EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_MANAGER,
            'full_name' => 'Manager ' . $reference,
            'ic_number' => '900101' . str_pad((string) $registration->id, 6, '0', STR_PAD_LEFT),
            'phone' => '0120000000',
            'email' => strtolower(str_replace('-', '', $reference)) . '@example.test',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        return $registration->fresh();
    }
}
