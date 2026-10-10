<?php

use App\Http\Middleware\EnforceInactivityTimeout;
use App\Http\Middleware\EnforceIpAllowlist;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserCanAccessAdmin;
use App\Http\Middleware\ObserveSuspiciousInput;
use App\Http\Middleware\PublicMaintenanceMode;
use App\Http\Middleware\ScopeTournamentToHandler;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            'session.timeout' => EnforceInactivityTimeout::class,
            'ip.allowlist' => EnforceIpAllowlist::class,
            'tournament.scope' => ScopeTournamentToHandler::class,
        ]);

        // Soft maintenance mode for the public site. The middleware itself
        // exempts /admin and signed in administrators.
        $middleware->appendToGroup('web', PublicMaintenanceMode::class);

        /*
         | Notices a probing pattern in request data for the Security Log. It has
         | ONE return statement and it is $next($request): it records and never
         | refuses, deliberately, because a pattern gate on a public registration
         | form would refuse a participant named O'Brien. See
         | App\Support\SuspiciousInput.
         */
        $middleware->appendToGroup('web', ObserveSuspiciousInput::class);

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
        /*
         | The Security Log is fed from HERE, and almost entirely from here.
         |
         | Every refusal the system already makes raises an exception that lands on
         | this one hook — the permission middleware's 403, the handler tournament
         | scope, a cross-tab id in User Management, a sponsor asking for another
         | sponsorship's id, a CSRF failure, a tampered or expired signed link, and
         | every rate limit. One hook rather than a call at each site, because a
         | call at each site is a call one site ends up missing.
         |
         | The callback RETURNS NULL, which is load bearing: Laravel's
         | renderViaCallbacks only uses a response that is not null, so returning
         | null leaves the refusal rendered exactly as it was before this log
         | existed. The recorder decides what is a refusal and what is not, and
         | swallows its own failures, so a logging fault leaves a 403 a 403.
         */
        $exceptions->render(function (\Throwable $exception, Request $request) {
            SecurityEventRecorder::fromException($exception, $request);

            return null;
        });
    })->create();
