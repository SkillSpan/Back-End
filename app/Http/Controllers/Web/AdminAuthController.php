<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
 *
 * The credential check deliberately mirrors Api\AuthController::attemptLogin
 * step for step — same email normalisation, same lookup, same Hash::check.
 * Anything the API accepts must be accepted here, otherwise the same
 * credentials "work on the API but not in the panel".
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
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Same normalisation as the API (strtolower + trim) and the same shape
        // the model stores (User::setEmailAttribute). Without it the panel and
        // the API can disagree on identical input.
        $email = mb_strtolower(trim($validated['email']));
        $password = $validated['password'];

        $throttleKey = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        // The account lookup and password check are written out rather than
        // collapsed into Auth::attempt(), for two reasons: it guarantees the
        // exact behaviour of the API login, and it lets a rejected attempt be
        // logged with the reason. `Auth::attempt() === false` cannot tell
        // "no such account" apart from "wrong password", which makes a report
        // like "it says the credentials do not match" impossible to diagnose.
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->reject($request, $throttleKey, 'no account for that email', [
                'email' => $email,
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->reject($request, $throttleKey, 'wrong password', [
                'email' => $email,
                'user_id' => $user->id,
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // A correct password is not sufficient: the panel is admin-only and a
        // suspended account must not be able to use it. The two refusals are
        // reported separately, and the role one names the role the account
        // actually holds, so an organisation admin signing in with perfectly
        // valid credentials does not read it as a wrong password.
        $roles = $user->roles()->pluck('slug');

        $refusal = match (true) {
            $user->status !== 'active' => 'This account is not active, so it cannot use the admin panel.',
            ! $user->hasRole('admin') => 'This account is signed in as "'
                .($roles->implode(', ') ?: 'no role')
                .'", but the admin panel requires the platform "admin" role.',
            default => null,
        };

        if ($refusal !== null) {
            $this->reject($request, $throttleKey, 'account not permitted', [
                'email' => $email,
                'user_id' => $user->id,
                'status' => $user->status,
                'roles' => $roles->all(),
            ]);

            throw ValidationException::withMessages(['email' => $refusal]);
        }

        RateLimiter::clear($throttleKey);

        // Reached only after the password and the authorization checks both
        // passed, so no session exists that has to be torn down again.
        Auth::login($user, $request->boolean('remember'));

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

    /**
     * Record a refused sign-in attempt.
     *
     * Failed admin sign-ins are worth keeping for security monitoring anyway;
     * they are also the only way to tell which step turned an operator away.
     * The caller still returns a deliberately vague message, so nothing here
     * leaks whether an address is registered.
     */
    private function reject(Request $request, string $throttleKey, string $reason, array $context): void
    {
        RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        Log::warning('Admin panel sign-in refused: '.$reason.'.', $context + [
            'ip' => $request->ip(),
        ]);
    }
}
