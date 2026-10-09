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
     * The two admin rate limits set on General Config, Security.
     *
     * Read from the settings on every request rather than at boot, so a change
     * applies on the next request without a deploy. The callbacks only run for
     * the routes that name them, so defining them here costs a public request
     * nothing.
     *
     * ADMIN ROUTES ONLY. Neither limiter may be attached to the public site,
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
