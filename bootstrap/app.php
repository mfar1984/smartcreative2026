<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserCanAccessAdmin;
use App\Http\Middleware\PublicMaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Admin routes live in their own file to keep the public site
            // routes readable.
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserCanAccessAdmin::class,
            'permission' => EnsurePermission::class,
        ]);

        // Soft maintenance mode for the public site. The middleware itself
        // exempts /admin and signed in administrators.
        $middleware->appendToGroup('web', PublicMaintenanceMode::class);

        // The gateway posts here without a session. It proves who it is with an
        // RSA signature over the raw body, which the controller verifies.
        $middleware->validateCsrfTokens(except: [
            'payments/chip/webhook',

            // Infobip posts delivery reports here without a session. It cannot
            // hold a CSRF token, and it does not sign the body either, so the
            // secret in the path is what the controller checks instead.
            'sms/infobip/delivery/*',
        ]);

        // The admin area is the only authenticated part of the site, so guests
        // are always sent to the admin sign in screen, and a signed in admin
        // hitting that screen is sent on to the dashboard.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
