<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\AdminLogger;
use App\Services\Shipping\EasyParcelOAuth;
use App\Services\Shipping\ShippingException;
use App\Support\ShippingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authorising an EasyParcel account against this application.
 *
 * Split out of IntegrationController because this is not settings editing. It is a
 * three legged redirect through somebody else's login screen, and it needs its own
 * routes, its own session state and its own failure handling.
 *
 * The callback path is fixed by the Allowed Redirect URIs registered on the app in
 * the EasyParcel developer hub. Renaming the route breaks the connection until the
 * dashboard is edited to match, so the route name and the registered URI have to be
 * changed together.
 */
class EasyParcelController extends Controller
{
    public function __construct(private readonly EasyParcelOAuth $oauth)
    {
    }

    /**
     * Send the administrator to EasyParcel to sign in and grant access.
     */
    public function connect(Request $request)
    {
        try {
            $state = $this->oauth->freshState();

            $url = $this->oauth->authorizeUrl($this->redirectUri(), $state);
        } catch (ShippingException $exception) {
            return $this->backWithError($exception->getMessage());
        }

        /*
         | Held in the session rather than signed into the URL. The value has to
         | survive a round trip through a site this application does not control,
         | and comparing it on return is the whole point: without it, anyone could
         | hand this callback a code of their choosing.
         */
        $request->session()->put(EasyParcelOAuth::STATE_SESSION_KEY, $state);

        return redirect()->away($url);
    }

    /**
     * Where EasyParcel sends the administrator back, with a code to exchange.
     */
    public function callback(Request $request)
    {
        $expected = $request->session()->pull(EasyParcelOAuth::STATE_SESSION_KEY);
        $returned = $request->query('state');

        /*
         | Pulled before anything else so a state can only ever be used once, and
         | compared with hash_equals rather than === because this is a security
         | comparison and timing should not vary with how much of it matched.
         */
        if (! is_string($expected) || ! is_string($returned) || ! hash_equals($expected, $returned)) {
            return $this->backWithError(
                'The reply from EasyParcel did not carry the value this application sent, so it was refused. Press Connect Account again, and do not open the callback link directly.'
            );
        }

        // EasyParcel reports a refusal by sending the administrator back without a
        // code, so an absent code is a declined authorisation rather than a fault.
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->backWithError('EasyParcel did not return an authorisation code. The sign in was cancelled, or access was not granted.');
        }

        try {
            $payload = $this->oauth->exchangeCode($code, $this->redirectUri());
        } catch (ShippingException $exception) {
            Log::warning('EasyParcel authorisation could not be completed.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->backWithError($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('EasyParcel authorisation failed unexpectedly.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->backWithError('The connection could not be completed: ' . $exception->getMessage());
        }

        AdminLogger::activity(
            'settings.shipping.connected',
            sprintf(
                'Connected an EasyParcel account. Access token expires %s.',
                ShippingSettings::accessTokenExpiresAt()?->toDayDateTimeString() ?? 'at an unstated time',
            ),
        );

        /*
         | Which account was linked decides whether this is sandbox or live, and the
         | token payload is the only place that says. Logged rather than shown,
         | because the client_id is the only identifying field it carries.
         */
        Log::info('EasyParcel account authorised.', [
            'client_id' => data_get($payload, 'app.client_id'),
            'expires_at' => data_get($payload, 'expires_at'),
        ]);

        return redirect()
            ->route('admin.settings.integration', ['tab' => 'shipping'])
            ->with('status', 'EasyParcel account connected. Whether this is sandbox or live depends on the account you signed in with.');
    }

    /**
     * Forget the tokens, keeping the application credentials.
     *
     * The usual reason to press this is to authorise a different EasyParcel
     * account with the same application, such as swapping sandbox for live, so
     * the Client ID and Secret are deliberately left in place.
     */
    public function disconnect(Request $request)
    {
        ShippingSettings::forgetTokens();

        AdminLogger::activity(
            'settings.shipping.disconnected',
            'Disconnected the EasyParcel account. The Client ID and Secret were kept.',
        );

        return redirect()
            ->route('admin.settings.integration', ['tab' => 'shipping'])
            ->with('status', 'EasyParcel account disconnected. Orders fall back to the flat rate until an account is connected again.');
    }

    /**
     * The callback address, generated from the route rather than typed.
     *
     * It has to match a registered Allowed Redirect URI exactly, and it is sent
     * twice: once on the way out and again in the token exchange, where a
     * mismatch between the two is itself a refusal.
     */
    private function redirectUri(): string
    {
        return route('admin.settings.integration.easyparcel.callback');
    }

    private function backWithError(string $message)
    {
        return redirect()
            ->route('admin.settings.integration', ['tab' => 'shipping'])
            ->with('easyparcel_error', $message);
    }
}
