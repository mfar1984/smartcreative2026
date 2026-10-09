<?php

namespace Tests\Feature\Security;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Support\ParticipantOptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The public participant forms and the basket on the stadium Wi-Fi.
 *
 * On event day hundreds of phones reach us from the venue's one public IP. The old
 * throttle:10,1 counted that IP, so ten submissions a minute got through for the
 * whole venue and everybody else was told "Too Many Requests".
 *
 * Covers: registration and checkout carry public-form, ten a minute per browser
 * session under 120 per IP; the registration payment, size confirmation and shop
 * order pay routes carry public-reference, ten per signed link under the same
 * ceiling and ten per session for anything unsigned, tampered or expired; the
 * basket carries public-cart, sixty per session under 300 per IP, on counters the
 * forms never read; the session is open before the throttle reads it; eleven
 * phones on one IP all register, one phone is stopped on its eleventh; the 121st
 * from one IP is stopped whatever the session; two entries' links on one IP do not
 * share; the CHIP webhook and the payment returns stay unthrottled; admin-login is
 * unchanged. PublicReferenceSignedFormTest posts each signed form as it is drawn.
 *
 * A "phone" is a browser session: the session cookie is sent on every request, the
 * way a real browser sends it. A request without one gets a fresh session.
 *
 * Mail, the queue and outbound HTTP are faked, so no registration reaches anybody.
 */
class PublicFormRateLimitTest extends SecurityTestCase
{
    private const VENUE_IP = '203.0.113.200';

    /** Route name => the one throttle it must carry. */
    private const LIMITED = [
        'registration.store' => 'throttle:public-form',
        'checkout.place' => 'throttle:public-form',
        'registration.payment.pay' => 'throttle:public-reference',
        'registration.sizes.store' => 'throttle:public-reference',
        'shop.order.pay' => 'throttle:public-reference',
        'cart.store' => 'throttle:public-cart',
        'cart.update' => 'throttle:public-cart',
        'cart.clear' => 'throttle:public-cart',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::fake();
    }

    /* ---------------------------------------------------------------------
     | Which routes, and in what order
     * ------------------------------------------------------------------ */

    public function test_the_participant_forms_and_the_basket_carry_only_their_named_limiter(): void
    {
        foreach (self::LIMITED as $name => $limiter) {
            $throttles = array_values(array_filter(
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle'),
            ));

            // The named limiter, with no throttle:10,1 or throttle:60,1 left beside it.
            $this->assertSame([$limiter], $throttles, $name);
        }
    }

    public function test_the_session_is_started_before_the_throttle_reads_its_id(): void
    {
        // Resolving the kernel puts the web group and the middleware priority on
        // the router, which is what a real request runs through.
        $this->app->make(HttpKernel::class);

        foreach (self::LIMITED as $name => $limiter) {
            $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $session = array_search(StartSession::class, $stack, true);
            $throttle = array_search(ThrottleRequests::class . ':' . Str::after($limiter, 'throttle:'), $stack, true);

            $this->assertIsInt($session, $name);
            $this->assertIsInt($throttle, $name);
            $this->assertLessThan($throttle, $session, $name);
        }
    }

    public function test_the_chip_webhook_and_the_payment_returns_stay_unthrottled(): void
    {
        foreach (['payments.chip.webhook', 'registration.payment.return', 'shop.order.payment.return'] as $name) {
            foreach (Route::getRoutes()->getByName($name)->gatherMiddleware() as $middleware) {
                $this->assertStringStartsNotWith('throttle', $middleware, $name);
            }
        }
    }

    public function test_admin_login_keeps_its_own_limiter_at_ten_a_minute_per_ip(): void
    {
        $this->assertContains('throttle:admin-login', Route::getRoutes()->getByName('admin.login.attempt')->gatherMiddleware());

        $limit = RateLimiter::limiter('admin-login')(
            Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => self::VENUE_IP])
        );

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertLimit($limit, 10, self::VENUE_IP);
    }

    /* ---------------------------------------------------------------------
     | The limiter definitions
     * ------------------------------------------------------------------ */

    public function test_public_form_is_ten_a_minute_per_session_under_one_hundred_and_twenty_per_ip(): void
    {
        $phone = Str::random(40);

        $limits = $this->limits('public-form', $this->request(self::VENUE_IP, $phone, '/checkout'));

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 10, 'device:' . $phone);
        $this->assertLimit($limits[1], 120, 'network:' . self::VENUE_IP);

        // A second phone on the same Wi-Fi: a device budget of its own, the same ceiling.
        $other = $this->limits('public-form', $this->request(self::VENUE_IP, Str::random(40), '/checkout'));

        $this->assertNotSame($limits[0]->key, $other[0]->key);
        $this->assertSame($limits[1]->key, $other[1]->key);
    }

    public function test_public_reference_spends_a_links_ten_only_with_its_valid_signature(): void
    {
        $limits = $this->limits('public-reference', $this->request(self::VENUE_IP, Str::random(40), $this->sizeLink('REG-2026-0001')));

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 10, 'reference:REG-2026-0001');
        $this->assertLimit($limits[1], 120, 'network:' . self::VENUE_IP);

        // Another entry's link on the same Wi-Fi: its own ten, the same ceiling.
        $other = $this->limits('public-reference', $this->request(self::VENUE_IP, Str::random(40), $this->sizeLink('REG-2026-0002')));

        $this->assertLimit($other[0], 10, 'reference:REG-2026-0002');
        $this->assertSame($limits[1]->key, $other[1]->key);

        // The same reference guessed with no signature, carried over from another
        // entry's link, or on a link that has run out: counted against the session
        // that sent it, never against the link.
        $phone = Str::random(40);

        foreach ([
            'unsigned' => '/registration/sizes/REG-2026-0001',
            'tampered' => str_replace('REG-2026-0002', 'REG-2026-0001', $this->sizeLink('REG-2026-0002')),
            'expired' => $this->sizeLink('REG-2026-0001', now()->subMinute()),
        ] as $case => $url) {
            $limits = $this->limits('public-reference', $this->request(self::VENUE_IP, $phone, $url));

            $this->assertCount(2, $limits, $case);
            $this->assertLimit($limits[0], 10, 'device:' . $phone, $case);
            $this->assertLimit($limits[1], 120, 'network:' . self::VENUE_IP, $case);
        }
    }

    public function test_public_cart_is_sixty_a_minute_per_session_under_three_hundred_per_ip(): void
    {
        $phone = Str::random(40);

        $limits = $this->limits('public-cart', $this->request(self::VENUE_IP, $phone, '/cart'));

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 60, 'device:' . $phone);
        $this->assertLimit($limits[1], 300, 'network:' . self::VENUE_IP);
    }

    /* ---------------------------------------------------------------------
     | Behaviour
     * ------------------------------------------------------------------ */

    public function test_eleven_phones_on_the_venue_wifi_all_register(): void
    {
        $event = $this->openEvent();

        $this->fromIp(self::VENUE_IP);

        // One more than the old limit let through for the whole venue in a minute.
        for ($n = 1; $n <= 11; $n++) {
            $this->asPhone(Str::random(40))
                ->post(route('registration.store', ['event' => $event->slug]), [
                    'team_name' => 'Squad ' . $n,
                    'participants' => [$this->person($n * 10 + 1), $this->person($n * 10 + 2), $this->person($n * 10 + 3)],
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $this->assertSame(11, EventRegistration::query()->where('event_id', $event->id)->count());
    }

    public function test_one_phone_is_stopped_on_its_eleventh_submission_in_a_minute(): void
    {
        $event = $this->openEvent();
        $url = route('registration.store', ['event' => $event->slug]);

        $this->fromIp(self::VENUE_IP);

        $phone = Str::random(40);

        // An empty form: refused by validation, after the throttle has counted it.
        for ($i = 1; $i <= 10; $i++) {
            $this->asPhone($phone)->post($url, [])->assertRedirect();
        }

        $this->asPhone($phone)->post($url, [])->assertStatus(429);

        // The next phone on the same Wi-Fi is not held up by it.
        $this->asPhone(Str::random(40))->post($url, [])->assertRedirect();

        // A minute later the first phone may submit again.
        $this->travel(61)->seconds();
        $this->asPhone($phone)->post($url, [])->assertRedirect();
    }

    public function test_the_hundred_and_twenty_first_from_one_ip_is_stopped_across_many_phones(): void
    {
        $event = $this->openEvent();
        $url = route('registration.store', ['event' => $event->slug]);

        // 119 submissions this minute from the venue, written straight onto the
        // counter rather than sent one by one.
        RateLimiter::increment($this->counter('public-form', 'network:' . self::VENUE_IP), 60, 119);

        $this->fromIp(self::VENUE_IP);

        // The 120th, from a phone that has sent nothing, is let through.
        $this->asPhone(Str::random(40))->post($url, [])->assertRedirect();

        // The 121st, from another phone that has sent nothing, is not.
        $this->asPhone(Str::random(40))->post($url, [])->assertStatus(429);

        // Another network has its own ceiling.
        $this->fromIp('198.51.100.7')->asPhone(Str::random(40))->post($url, [])->assertRedirect();
    }

    public function test_each_entry_link_has_its_own_ten_and_two_on_one_ip_do_not_share(): void
    {
        $event = $this->openEvent();
        $first = $this->settledRegistration($event, 'REG-2026-0901');
        $second = $this->settledRegistration($event, 'REG-2026-0902');

        $this->fromIp(self::VENUE_IP);

        // Ten presses of one entry's Pay button, each from a fresh session, so it is
        // the reference that is counted and not the browser. Nothing is owed, so each
        // is sent back to the payment page without reaching the gateway.
        for ($i = 1; $i <= 10; $i++) {
            $this->post($this->payUrl($first))->assertRedirect();
        }

        $this->post($this->payUrl($first))->assertStatus(429);

        // The next entry's link on the same Wi-Fi has its own ten.
        $this->post($this->payUrl($second))->assertRedirect();
    }

    public function test_basket_traffic_never_touches_the_registration_and_checkout_counters(): void
    {
        $event = $this->openEvent();
        $phone = Str::random(40);

        $this->fromIp(self::VENUE_IP)->asPhone($phone);

        // More basket changes than the forms allow one phone in a minute. Empty, so
        // validation refuses each one after the throttle has counted it.
        for ($i = 1; $i <= 11; $i++) {
            $this->post(route('cart.store'), [])->assertRedirect();
        }

        $this->assertSame(11, $this->attempts('public-cart', 'device:' . $phone));
        $this->assertSame(11, $this->attempts('public-cart', 'network:' . self::VENUE_IP));
        $this->assertSame(0, $this->attempts('public-form', 'device:' . $phone));
        $this->assertSame(0, $this->attempts('public-form', 'network:' . self::VENUE_IP));

        // The whole venue's basket ceiling used up...
        RateLimiter::increment($this->counter('public-cart', 'network:' . self::VENUE_IP), 60, 300);

        $this->put(route('cart.update'), [])->assertStatus(429);

        // ...and the same phone on the same Wi-Fi still registers and checks out.
        $this->post(route('registration.store', ['event' => $event->slug]), [])->assertRedirect();
        $this->post(route('checkout.place'), [])->assertRedirect();

        $this->assertSame(2, $this->attempts('public-form', 'device:' . $phone));
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** Every following request carries this session cookie, as one phone's browser would. */
    private function asPhone(string $sessionId): static
    {
        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    /** A POST as the throttle sees it: from an IP, with an open session, matched to its real route. */
    private function request(string $ip, string $sessionId, string $url): Request
    {
        $request = Request::create($url, 'POST', server: ['REMOTE_ADDR' => $ip]);

        $request->setLaravelSession(new Store('test', new ArraySessionHandler(120), $sessionId));

        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /** @return array<int, Limit> */
    private function limits(string $name, Request $request): array
    {
        return RateLimiter::limiter($name)($request);
    }

    private function assertLimit(Limit $limit, int $perMinute, string $key, string $message = ''): void
    {
        $this->assertSame($perMinute, $limit->maxAttempts, $message);
        $this->assertSame(60, $limit->decaySeconds, $message);
        $this->assertSame($key, $limit->key, $message);
    }

    /**
     * The cache key ThrottleRequests counts one limit of a named limiter under: md5
     * of the limiter's name and the limit's key.
     */
    private function counter(string $limiter, string $key): string
    {
        return md5($limiter . $key);
    }

    private function attempts(string $limiter, string $key): int
    {
        return (int) RateLimiter::attempts($this->counter($limiter, $key));
    }

    /** A size confirmation link as the email carries it. The reference need not exist. */
    private function sizeLink(string $reference, ?\DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'registration.sizes',
            $expires ?? now()->addDays(30),
            ['reference' => $reference],
        );
    }

    private function openEvent(): Event
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
            'min_players' => 3,
            'max_players' => 5,
        ]);
    }

    /** An entry with nothing left to pay, so pressing Pay never reaches the gateway. */
    private function settledRegistration(Event $event, string $reference): EventRegistration
    {
        return EventRegistration::create([
            'event_id' => $event->id,
            'reference' => $reference,
            'mode' => $event->registration_mode,
            'team_name' => 'Squad ' . $reference,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'registration_fee' => 10,
            'addons_total' => 0,
            'amount' => 10,
            'amount_paid' => 10,
        ]);
    }

    private function payUrl(EventRegistration $registration): string
    {
        return URL::temporarySignedRoute(
            'registration.payment.pay',
            now()->addDays(30),
            ['reference' => $registration->reference],
        );
    }

    /** @return array<string, string> */
    private function person(int $n): array
    {
        return [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Dua',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'phone' => '0120000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'email' => 'member' . $n . '@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ];
    }
}
