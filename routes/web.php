<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Campaign\TrackingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\Messaging\InfobipDeliveryController;
use App\Http\Controllers\Payment\ChipWebhookController;
use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Controllers\Public\TournamentPublicController;
use App\Http\Controllers\RegistrationController;

// Home route
Route::get('/', [HomeController::class, 'index'])->name('home');

// Services routes
Route::get('/services', [MaintenanceController::class, 'services'])->name('services');

/*
| Tournament results.
|
| Hall of Fame reads the frozen champions, so it does not move when a score is
| corrected. The event ranking reads live standings and says how far through the
| tournament is, so a half-played table is not taken for a final result. Neither
| shows any personal detail beyond a competitor's name.
*/
Route::get('/hall-of-fame', [TournamentPublicController::class, 'hallOfFame'])->name('hall-of-fame');
Route::get('/events/{slug}/ranking', [TournamentPublicController::class, 'ranking'])->name('events.ranking');
Route::get('/services/event-management', [MaintenanceController::class, 'eventManagement'])->name('services.event-management');
Route::get('/services/online-registration', [MaintenanceController::class, 'onlineRegistration'])->name('services.online-registration');
Route::get('/services/digital-creative', [MaintenanceController::class, 'digitalCreative'])->name('services.digital-creative');

/*
| Campaign tracking. Reached by strangers from links inside email, so identified
| by an unguessable token rather than by a session or an id.
|
| The click route resolves its destination through {link}, a bound model, and never
| from a URL in the request. Accepting a destination would make this an open
| redirect: anyone could hand out a link on this domain that lands on a site of
| their choosing, which is how a phishing page borrows a trusted name.
|
| Unsubscribe is split in two on purpose. Mail clients prefetch links to build
| previews and to scan for threats, so a GET that changed data would remove people
| who never pressed anything. The GET shows a button; the POST acts.
*/

/*
| Infobip telling us whether a text message actually arrived.
|
| Infobip does not sign delivery reports, so the secret in the path is the whole
| authentication. It is handed over per message in notifyUrl, so it never appears
| anywhere a stranger could read it, and a wrong one gets a 404 rather than a
| refusal that would confirm the endpoint exists.
|
| The GET exists only so that pasting the address into a browser answers the
| question somebody is actually asking, which is "is this working". It reads
| nothing and writes nothing; the POST is the real endpoint.
*/
Route::get('/sms/infobip/delivery/{secret}', [InfobipDeliveryController::class, 'status'])
    ->name('sms.infobip.delivery.status');

Route::post('/sms/infobip/delivery/{secret}', [InfobipDeliveryController::class, 'report'])
    ->name('sms.infobip.delivery');

Route::prefix('c')->name('campaign.')->group(function () {
    Route::get('{token}/open.gif', [TrackingController::class, 'open'])->name('open');
    Route::get('{token}/l/{link}', [TrackingController::class, 'click'])->name('click');
    Route::get('{token}/unsubscribe', [TrackingController::class, 'unsubscribeForm'])->name('unsubscribe');
    Route::post('{token}/unsubscribe', [TrackingController::class, 'unsubscribe'])
        ->middleware('throttle:20,1')
        ->name('unsubscribe.confirm');
});

// Registration routes
Route::get('/registration', [RegistrationController::class, 'index'])->name('registration');
// Throttled because it writes participant records from an unauthenticated form.
Route::post('/registration/{event:slug}', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('registration.store');
/*
| The payment pages sit above the catch all slug route below so a reference is
| never mistaken for an event. Each is signed: the reference is a predictable
| sequence, and the page shows what was ordered and what is owed.
*/
Route::middleware('signed')->group(function () {
    Route::get('/registration/payment/{reference}', [RegistrationPaymentController::class, 'show'])
        ->name('registration.payment');

    Route::post('/registration/payment/{reference}/pay', [RegistrationPaymentController::class, 'pay'])
        ->middleware('throttle:10,1')
        ->name('registration.payment.pay');

    Route::get('/registration/payment/{reference}/return/{outcome}', [RegistrationPaymentController::class, 'handleReturn'])
        ->name('registration.payment.return');
});

Route::get('/registration/{slug}', [RegistrationController::class, 'show'])->name('registration.show');

/*
|--------------------------------------------------------------------------
| Payment callbacks
|--------------------------------------------------------------------------
|
| These URLs are handed to the gateway, so their paths are part of the
| integration contract and must not change casually. The webhook is exempt
| from CSRF in bootstrap/app.php because it is a server to server POST that
| authenticates itself with a signature instead of a session token.
|
*/
Route::post('/payments/chip/webhook', ChipWebhookController::class)->name('payments.chip.webhook');

// Portfolio route
Route::get('/portfolio', [MaintenanceController::class, 'portfolio'])->name('portfolio');

// Shop route
Route::get('/shop', [MaintenanceController::class, 'shop'])->name('shop');

/*
| Policy pages.
|
| CHIP will not approve a live merchant account without a refund policy, a privacy
| policy and a shipping policy, each reachable at its own address on our own domain.
| The footer already listed three policies as links, but every one of them pointed at
| "#", so the pages were advertised without existing.
|
| Single segment paths, so they cannot collide with the campaign tracking routes above,
| which all sit under the "c" prefix and need two segments.
*/
Route::get('/privacy-policy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms-of-service', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/cookie-policy', [LegalController::class, 'cookies'])->name('legal.cookies');
Route::get('/refund-policy', [LegalController::class, 'refund'])->name('legal.refund');
Route::get('/shipping-policy', [LegalController::class, 'shipping'])->name('legal.shipping');

// Contact routes
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contact.store');
