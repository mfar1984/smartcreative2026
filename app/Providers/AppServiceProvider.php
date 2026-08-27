<?php

namespace App\Providers;

use App\Support\MailSettings;
use Illuminate\Mail\MailManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
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
