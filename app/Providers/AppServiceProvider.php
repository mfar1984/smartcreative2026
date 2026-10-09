<?php

namespace App\Providers;

use App\Support\MailSettings;
use App\Support\SecuritySettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /*
     | The public participant forms, sized for the stadium on event day. Hundreds of
     | phones on the venue Wi-Fi share one public IP, so the old per-IP throttle:10,1
     | let ten through a minute for the whole venue. Each phone, and each signed link,
     | now gets the ten one person always had; the IP keeps only a ceiling. Do not
     | tighten these back to a per-IP 10.
     */
    private const PUBLIC_PER_DEVICE_PER_MINUTE = 10;

    private const PUBLIC_PER_REFERENCE_PER_MINUTE = 10;

    private const PUBLIC_PER_NETWORK_PER_MINUTE = 120;

    // The basket on the same Wi-Fi: the 60 a phone one person had under the old
    // per-IP throttle:60,1, under a ceiling above the forms' because add, change and
    // remove are chattier than one form post. Do not tighten back to a per-IP 60.
    private const PUBLIC_CART_PER_DEVICE_PER_MINUTE = 60;

    private const PUBLIC_CART_PER_NETWORK_PER_MINUTE = 300;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applySavedMailProfile();

        /*
         | One pager for all 21 admin tables, registered here rather than passed to
         | every ->links() call.
         |
         | Laravel's built in Tailwind view carries `dark:` variants, and under
         | Tailwind v4 those follow the operating system colour preference. On a
         | machine set to dark mode the pager rendered as a dark block while the rest
         | of the admin stayed light, because nothing else here has a dark theme.
         */
        Paginator::defaultView('vendor.pagination.admin');
        Paginator::defaultSimpleView('vendor.pagination.admin');

        $this->configureRateLimiting();
    }

    /**
     * The two admin rate limits set on General Config, Security, and the fixed
     * public ones for the participant forms and the basket.
     *
     * Read from the settings on every request rather than at boot, so a change
     * applies on the next request without a deploy. The callbacks only run for
     * the routes that name them, so defining them here costs a public request
     * nothing.
     *
     * ADMIN ROUTES ONLY. Neither admin limiter may be attached to the public site,
     * registration, checkout or the payment callbacks: on event day hundreds of
     * participants on the stadium Wi-Fi share one public IP, and a limit keyed on
     * that IP would refuse a whole venue at once.
     */
    private function configureRateLimiting(): void
    {
        // POST admin/login, per IP. The default of 10 is exactly the old
        // throttle:10,1, with its own counter instead of one shared with every
        // other unnamed throttle on the site.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(SecuritySettings::loginAttemptsPerMinute())
                ->by((string) $request->ip());
        });

        // The authenticated admin group, per user, so one busy account cannot
        // slow down the next. Off (0) by default, which is today's behaviour.
        RateLimiter::for('admin-requests', function (Request $request) {
            $perMinute = SecuritySettings::adminRequestsPerMinute();

            if ($perMinute === 0) {
                return Limit::none();
            }

            return Limit::perMinute($perMinute)
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // Public forms with no reference of their own: registration and checkout.
        // Per browser session, which StartSession has opened before any throttle
        // runs, under a ceiling for the whole IP. Every limit must pass.
        RateLimiter::for('public-form', function (Request $request) {
            return [
                Limit::perMinute(self::PUBLIC_PER_DEVICE_PER_MINUTE)
                    ->by('device:' . $request->session()->getId()),
                Limit::perMinute(self::PUBLIC_PER_NETWORK_PER_MINUTE)
                    ->by('network:' . $request->ip()),
            ];
        });

        // Public forms bound to one registration or order by its {reference}:
        // paying, confirming sizes, paying a shop order. Only a request carrying
        // the link's valid signature spends the link's budget. References run in
        // sequence and are easy to guess, so anything unsigned, tampered or
        // expired is counted against its own session instead and can never use
        // up a participant's link. Asked here because the throttle runs before
        // the signed middleware and before the size controller's own check; it
        // is the same check on the same request, so all three agree.
        RateLimiter::for('public-reference', function (Request $request) {
            $own = $request->hasValidSignature()
                ? Limit::perMinute(self::PUBLIC_PER_REFERENCE_PER_MINUTE)
                    ->by('reference:' . $request->route('reference'))
                : Limit::perMinute(self::PUBLIC_PER_DEVICE_PER_MINUTE)
                    ->by('device:' . $request->session()->getId());

            return [
                $own,
                Limit::perMinute(self::PUBLIC_PER_NETWORK_PER_MINUTE)
                    ->by('network:' . $request->ip()),
            ];
        });

        // The basket: add, change and empty. Per browser session under a ceiling
        // for the whole IP, on counters of its own, so a busy shop at the venue
        // can never use up the ceiling registration and checkout depend on.
        RateLimiter::for('public-cart', function (Request $request) {
            return [
                Limit::perMinute(self::PUBLIC_CART_PER_DEVICE_PER_MINUTE)
                    ->by('device:' . $request->session()->getId()),
                Limit::perMinute(self::PUBLIC_CART_PER_NETWORK_PER_MINUTE)
                    ->by('network:' . $request->ip()),
            ];
        });
    }

    /**
     * Let the SMTP profile saved on the Integration screen decide how mail is
     * sent, instead of it being recorded and then ignored.
     *
     * Hooked to the mail manager rather than run on every boot for two reasons:
     * a request that sends no mail should not query the settings table, and this
     * runs late enough that the database connection is certainly ready.
     */
    private function applySavedMailProfile(): void
    {
        $this->app->afterResolving(MailManager::class, function () {
            try {
                MailSettings::apply();
            } catch (Throwable $exception) {
                // A missing settings table means a fresh install part way
                // through migrating. Mail then falls back to .env, which is the
                // right outcome, so this is noted and not raised.
                Log::warning('Saved mail profile could not be applied.', [
                    'reason' => $exception->getMessage(),
                ]);
            }
        });
    }
}
