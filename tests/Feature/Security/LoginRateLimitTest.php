<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * The two configurable rate limits.
 *
 * Covers: POST admin/login carries the admin-login named limiter instead of the
 * old throttle:10,1; its default of 10 a minute matches the old limit exactly; a
 * saved value applies on the next request with no deploy, per IP; the admin request
 * limit is off at 0, and when set it throttles one user without touching another.
 */
class LoginRateLimitTest extends SecurityTestCase
{
    public function test_the_sign_in_post_uses_the_named_limiter_and_the_form_is_not_throttled(): void
    {
        $post = Route::getRoutes()->getByName('admin.login.attempt')->gatherMiddleware();
        $get = Route::getRoutes()->getByName('admin.login')->gatherMiddleware();

        $this->assertContains('throttle:admin-login', $post);
        $this->assertNotContains('throttle:10,1', $post);

        foreach ($get as $middleware) {
            $this->assertStringStartsNotWith('throttle', $middleware);
        }
    }

    public function test_the_default_of_ten_sign_in_attempts_a_minute_matches_the_old_limit(): void
    {
        $this->fromIp('203.0.113.20');

        for ($i = 1; $i <= 10; $i++) {
            $this->failedSignIn()->assertRedirect();
        }

        $this->failedSignIn()->assertStatus(429);

        // A minute later the address may try again.
        $this->travel(61)->seconds();
        $this->failedSignIn()->assertRedirect();
    }

    public function test_a_saved_limit_applies_on_the_next_request_per_ip(): void
    {
        $this->security(['login_attempts_per_minute' => '3']);
        $this->fromIp('203.0.113.21');

        $this->failedSignIn()->assertRedirect();
        $this->failedSignIn()->assertRedirect();
        $this->failedSignIn()->assertRedirect();
        $this->failedSignIn()->assertStatus(429);

        // Another network has its own allowance.
        $this->fromIp('203.0.113.22')->failedSignIn()->assertRedirect();

        // Raised again, the first address is let back in without waiting.
        $this->security(['login_attempts_per_minute' => '10']);
        $this->fromIp('203.0.113.21')->failedSignIn()->assertRedirect();
    }

    public function test_admin_requests_per_minute_of_zero_is_no_limit(): void
    {
        $limiter = RateLimiter::limiter('admin-requests');
        $this->assertInstanceOf(Unlimited::class, $limiter(Request::create('/admin')));

        $admin = $this->administrator();

        // Past the floor of 60 that any non-zero value would impose.
        for ($i = 1; $i <= 65; $i++) {
            $this->actingAs($admin)->get(route('admin.settings.general', ['tab' => 'maintenance']))->assertOk();
        }
    }

    public function test_a_non_zero_admin_request_limit_throttles_one_user_and_not_another(): void
    {
        $this->security(['admin_requests_per_minute' => '60']);

        $busy = $this->administrator();
        $other = $this->administrator();

        for ($i = 1; $i <= 60; $i++) {
            $this->actingAs($busy)->get(route('admin.settings.general', ['tab' => 'maintenance']))->assertOk();
        }

        $this->actingAs($busy)->get(route('admin.settings.general', ['tab' => 'maintenance']))->assertStatus(429);

        // Same network, different account: unaffected.
        $this->actingAs($other)->get(route('admin.settings.general', ['tab' => 'maintenance']))->assertOk();
    }

    public function test_a_stored_admin_request_limit_below_sixty_is_raised_to_sixty(): void
    {
        $this->security(['admin_requests_per_minute' => '5']);

        $admin = $this->administrator();

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($admin)->get(route('admin.settings.general', ['tab' => 'maintenance']))->assertOk();
        }
    }
}
