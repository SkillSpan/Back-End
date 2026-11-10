<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PanelAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

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

        $email = mb_strtolower(trim($validated['email']));
        $password = $validated['password'];

        $throttleKey = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

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

        $roles = $user->roles()->pluck('slug');

        $refusal = match (true) {
            $user->status !== 'active' => 'This account is not active, so it cannot use the admin panel.',
            ! PanelAccess::allows($user) => 'This account is signed in as "'
                .($roles->implode(', ') ?: 'no role')
                .'", but the admin panel requires the platform "admin" role or a mentor profile.',
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

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(
            PanelAccess::isAdministrator($user)
                ? route('admin.organizations')
                : route('admin.support'),
        );
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function reject(Request $request, string $throttleKey, string $reason, array $context): void
    {
        RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        Log::warning('Admin panel sign-in refused: '.$reason.'.', $context + [
            'ip' => $request->ip(),
        ]);
    }
}
