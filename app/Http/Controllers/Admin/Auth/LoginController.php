<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create()
    {
        return view('admin.auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $user = $request->user();

        // Authentication succeeded, but this account may still not be allowed
        // into the admin area. Reject it and end the session immediately.
        if (! $user->canAccessAdmin()) {
            AdminLogger::activity(
                'auth.denied',
                'Sign in refused: account is inactive or has no admin access.',
                $user->id,
                $user->logLabel(),
            );

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'username' => 'This account does not have access to the admin area.',
            ]);
        }

        // Guards against session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AdminLogger::activity('auth.login', 'Signed in to the admin area.');

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request)
    {
        AdminLogger::activity('auth.logout', 'Signed out of the admin area.');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
