<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-in for the browser-based admin review panel.
 *
 * The panel used to ask the operator to paste a Sanctum Bearer token into a
 * text box, which meant every reviewer needed a token minted out of band and
 * the page could only ever answer "ما قدرنا نجيب الطلبات: Unauthenticated."
 * when it was missing. This establishes a normal session instead, so the
 * panel is reachable by signing in with an email and password.
 */
class AdminAuthController extends Controller
{
    /**
     * Maximum failed attempts per email+IP before the form locks out.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Lockout window, in seconds.
     */
    private const DECAY_SECONDS = 60;

    public function showLoginForm(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle on email+IP so the panel cannot be brute forced from the
        // browser. Mirrors the throttle the API login route applies.
        $throttleKey = mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        // A correct password is not sufficient: this panel is admin-only,
        // and a suspended account must not be able to use it at all. The
        // session has to be dropped here, otherwise a learner who happens to
        // know their own password would walk straight into the review panel.
        if (! $user->hasRole('admin') || $user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'This account is not allowed to access the admin panel.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        // New session id on privilege change, to defeat session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.organizations'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
