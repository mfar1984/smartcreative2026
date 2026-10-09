<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Campaign\TrackingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\Messaging\InfobipDeliveryController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Payment\ChipWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ParticipantSizeController;
use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Http\Controllers\Public\PlayerMessageController;
use App\Http\Controllers\Public\TournamentPublicController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WifiProvisionController;

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

/*
| The archive: every tournament that is over, announced or not, counted from the
| match rows it already has. Keyed on the tournament being closed rather than on the
| event's dates, so a date passing never files a tournament still being played under
| "past".
*/
Route::get('/archive', [TournamentPublicController::class, 'archive'])->name('archive');

/*
| One team's record in one event, reached by tapping its name on the ranking above.
|
| Keyed on the registration rather than the team name, because a name is typed by
| whoever registered and two squads may well choose the same one. Declared after the
| ranking route so "ranking" is never read as a team id.
*/
Route::get('/events/{slug}/team/{registration}', [TournamentPublicController::class, 'team'])
    ->whereNumber('registration')
    ->name('events.team');

/*
| One competitor across every event they have entered, reached by tapping a name on a
| leaderboard or a roster.
|
| Not nested under an event, because the page's whole purpose is to cross events. Keyed
| on the registration row that was tapped rather than on the identity card number that
| joins the rows together, so the number is never in a URL and nobody can test whether
| a card they know is registered.
*/
Route::get('/player/{participant}', [TournamentPublicController::class, 'player'])
    ->whereNumber('participant')
    ->name('player');

/*
| Passing a message to a competitor without handing over their details.
|
| The profile shows a masked address; this is what is behind it. The message reaches
| the office, which holds the real address and decides whether to forward anything.
| Throttled on the same terms as the contact form, which is the only protection either
| of them has beyond the CSRF token.
*/
Route::post('/player/{participant}/message', [PlayerMessageController::class, 'store'])
    ->whereNumber('participant')
    ->middleware('throttle:5,1')
    ->name('player.message');
/*
| The venue's router asking which Wi-Fi accounts to create.
|
| Outbound from the router and inbound to us, which is the whole design: the router
| never has to accept a connection, so its management interface stays closed to the
| internet. The alternative was exposing RouterOS so this application could reach in and
| create accounts, and a router is the one box on a network whose compromise takes
| everything behind it.
|
| The secret in the path is the whole authentication, for the same reason as the Infobip
| route above: a scheduled fetch on a network device cannot hold a session or a CSRF
| token. It is scoped to one event, so a leak stops there, and the credentials it returns
| expire with the event, which is the protection that still holds once it has leaked.
|
| Throttled because each call writes a provisioned_at across every row for the event. A
| scheduled fetch runs every few minutes at most; anything faster is not a router.
*/
Route::get('/wifi/{token}/provision.rsc', [WifiProvisionController::class, 'script'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:20,1')
    ->name('wifi.provision');

/*
| The three service pages. Each is laid out differently on purpose: they are bought
| for different reasons, and a visitor comparing them should be able to tell them
| apart rather than reading three variations of the same grid.
*/
Route::get('/services/event-management', [ServiceController::class, 'eventManagement'])->name('services.event-management');
Route::get('/services/online-registration', [ServiceController::class, 'onlineRegistration'])->name('services.online-registration');
Route::get('/services/digital-creative', [ServiceController::class, 'digitalCreative'])->name('services.digital-creative');

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
    ->middleware('throttle:public-form')
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
        ->middleware('throttle:public-reference')
        ->name('registration.payment.pay');

    /*
    | Applying a voucher code to an entry that has already been submitted.
    |
    | POST, because it claims a code and moves money. The controller refuses it once
    | anything has been paid, whatever this link says, because a signed link lives
    | thirty days and the page it came from can be long out of date.
    */
    Route::post('/registration/payment/{reference}/coupon', [RegistrationPaymentController::class, 'applyCoupon'])
        ->middleware('throttle:public-reference')
        ->name('registration.payment.coupon');

    Route::get('/registration/payment/{reference}/return/{outcome}', [RegistrationPaymentController::class, 'handleReturn'])
        ->name('registration.payment.return');
});

/*
| Confirming the shirt size of everybody on one registration.
|
| Above the catch all slug route below, for the same reason the payment pages are: a
| reference must never be read as an event slug.
|
| Deliberately NOT in the `signed` group above, even though both routes are signed. That
| middleware answers an expired link by throwing, and the people holding these links are
| participants: the controller checks the signature itself so an expired one gets a page
| that says so rather than a stack trace. The signature covers the whole URL including
| the reference, so it cannot be edited to reach another entry, and the registration is
| resolved from the route rather than from anything posted.
|
| Two routes on one path. The GET draws the form; the POST records the answer, throttled
| because it is an unauthenticated write, and it carries no money of any kind.
*/
Route::get('/registration/sizes/{reference}', [ParticipantSizeController::class, 'show'])
    ->name('registration.sizes');

Route::post('/registration/sizes/{reference}', [ParticipantSizeController::class, 'store'])
    ->middleware('throttle:public-reference')
    ->name('registration.sizes.store');

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

/*
| Portfolio. Reads the portfolio_projects table, published entries only, so a
| write up can be drafted over several sittings without appearing half finished on
| the live site.
*/
Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');

/*
| Shop. Active products only, and only once the shop has been opened in settings.
|
| There is no cart or checkout: products carry an enquiry route instead. The listing
| is declared above the product route so "shop" itself is never read as a slug.
*/
Route::get('/shop', [ShopController::class, 'index'])->name('shop');
Route::get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.product');

/*
| Basket and checkout.
|
| At the root rather than under /shop, because /shop/{slug} would otherwise shadow
| them and a product whose slug happened to be "cart" could never be reached.
|
| Adding and changing the basket is throttled: it writes to the session on every
| call and is reachable without a login.
*/
Route::post('/cart', [CartController::class, 'store'])
    ->middleware('throttle:public-cart')
    ->name('cart.store');
Route::get('/cart', [CartController::class, 'index'])->name('cart');
Route::put('/cart', [CartController::class, 'update'])
    ->middleware('throttle:public-cart')
    ->name('cart.update');
Route::delete('/cart', [CartController::class, 'destroy'])
    ->middleware('throttle:public-cart')
    ->name('cart.clear');

Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'place'])
    ->middleware('throttle:public-form')
    ->name('checkout.place');

/*
| Checking a voucher code before anybody commits to it.
|
| Read only: it claims nothing and changes nothing. The claim happens when the
| registration or the checkout form is actually submitted, so a visitor who checks a
| code and walks away has not spent it.
|
| On the basket's own throttle rather than the form one. It is pressed while somebody
| is still filling a form in, so it must not use up the budget that submitting the
| form itself depends on.
*/
Route::post('/voucher/check', [VoucherController::class, 'check'])
    ->middleware('throttle:public-cart')
    ->name('voucher.check');

/*
| Order confirmation. Signed, because references run in sequence: without a
| signature anybody could count upwards and read a stranger's name, address and
| phone number.
*/
Route::get('/order/{reference}', [CheckoutController::class, 'confirmation'])
    ->middleware('signed')
    ->name('shop.order');

/*
| The buyer saying the parcel arrived, which is how a cash on delivery order is
| settled: nobody here can observe the courier handing it over.
|
| Split in two on purpose, the same way the campaign unsubscribe is. Mail clients
| prefetch links to build previews, so a GET that recorded the confirmation would
| mark parcels received that nobody had touched. The GET shows a button; the POST
| acts.
*/
Route::get('/order/{reference}/received', [CheckoutController::class, 'confirmReceiptForm'])
    ->middleware('signed')
    ->name('shop.order.received');

Route::post('/order/{reference}/received', [CheckoutController::class, 'confirmReceipt'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('shop.order.received.confirm');

/*
| Proof of a manual bank transfer. Signed for the same reason as the pages above:
| references run in sequence, so an unsigned link would let anybody count upwards
| through other people's orders.
|
| Neither route marks anything paid. They collect the evidence somebody in the admin
| then checks against the bank.
*/
Route::get('/order/{reference}/receipt', [CheckoutController::class, 'receiptForm'])
    ->middleware('signed')
    ->name('shop.order.receipt');

Route::post('/order/{reference}/receipt', [CheckoutController::class, 'storeReceipt'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('shop.order.receipt.store');

/*
| Paying a shop order on the gateway.
|
| POST rather than GET for the same reason the delivery confirmation is: mail clients
| and threat scanners prefetch links, and a GET that opened a purchase would create
| one at the gateway for every preview.
|
| Signed, so no amount, order or status can be tampered in. Neither appears in the URL
| at all: the figure is recomputed from the database on every request.
*/
Route::post('/order/{reference}/pay', [ShopOrderPaymentController::class, 'pay'])
    ->middleware(['signed', 'throttle:public-reference'])
    ->name('shop.order.pay');

/*
| Applying a voucher code to an order that has already been placed.
|
| POST for the same reasons as paying: it claims a code and moves the total, and a
| prefetched GET would spend somebody's coupon on a link preview. The controller
| refuses it once anything has been paid.
*/
Route::post('/order/{reference}/coupon', [ShopOrderPaymentController::class, 'applyCoupon'])
    ->middleware(['signed', 'throttle:public-reference'])
    ->name('shop.order.coupon');

/*
| Where the gateway sends the buyer back. The outcome in the URL is a hint only; the
| status comes from the signed webhook or from a direct read of the purchase.
*/
Route::get('/order/{reference}/payment/{outcome}', [ShopOrderPaymentController::class, 'handleReturn'])
    ->middleware('signed')
    ->name('shop.order.payment.return');



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
