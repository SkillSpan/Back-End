<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\CheckEmailRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateAccountRequest;
use App\Http\Requests\Auth\VerifyPasswordResetRequest;
use App\Http\Requests\Auth\VerifyRequest;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->registerIndividual($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Your account has been created successfully. Please check your email to verify your account.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => $user->status,
                'requires_verification' => true,
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'privacy_accepted_at' => $user->privacy_accepted_at?->toIso8601String(),
                'resend_available_at' => now()
                    ->addSeconds((int) config('verification.resend_interval_seconds', 60))
                    ->toIso8601String(),
            ],
        ], 201);
    }

    public function registerOrganization(RegisterOrganizationRequest $request): JsonResponse
    {
        $user = $this->authService->registerOrganization($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Your organization account has been created successfully. Your proof document will be reviewed by an administrator before your account is activated.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => $user->status,
                'requires_verification' => false,
            ],
        ], 201);
    }

    public function checkEmail(CheckEmailRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $exists = $this->authService->emailExists($email);

        return response()->json([
            'success' => true,
            'message' => 'Email availability checked.',
            'data' => [
                'email' => $email,
                'exists' => $exists,
                'available' => ! $exists,
            ],
        ]);
    }

    public function verify(VerifyRequest $request): JsonResponse
    {
        $verified = $this->authService->verifyOtp(
            $request->input('email'),
            $request->input('otp')
        );

        if (! $verified) {
            throw ValidationException::withMessages([
                'otp' => 'The verification code is invalid or has expired.',
            ]);
        }

        $user = User::where('email', strtolower(trim($request->input('email'))))->first();
        $isOrganizationMember = $user && $user->organizations()->exists();

        return response()->json([
            'success' => true,
            'message' => $isOrganizationMember
                ? 'Your email has been confirmed. Please wait until your account has been verified.'
                : 'Your account has been activated successfully. You can now log in.',
            'data' => [
                'redirect_url' => '/login',
            ],
        ]);
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $decaySeconds = (int) config('verification.resend_interval_seconds', 60);

        $neutralResponse = fn () => response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a new verification code has been sent.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);

        $key = 'resend-otp:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return $neutralResponse();
        }

        RateLimiter::hit($key, $decaySeconds);

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->authService->resendOtp($user);
        }

        return $neutralResponse();
    }

    public function loginWithGoogle(GoogleLoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginWithGoogle(
            $request->input('credential'),
            (bool) $request->input('terms_accepted', false),
            (bool) $request->input('privacy_accepted', false),
            $request
        );

        return response()->json([
            'success' => true,
            'message' => $result['created']
                ? 'Account created and logged in successfully with Google.'
                : 'Logged in successfully with Google.',
            'data' => [
                'user' => $result['user'],
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'is_new_user' => $result['created'],
            ],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        [$user, $tokenResult] = $this->attemptLogin($request, 'individual');

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user->load(['roles', 'studentProfile']),
                'token' => $tokenResult->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function loginOrganization(LoginRequest $request): JsonResponse
    {
        [$user, $tokenResult, $organization] = $this->attemptLogin($request, 'organization');

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user->load('roles'),
                'organizations' => [$organization],
                'token' => $tokenResult->plainTextToken,
                'token_type' => 'Bearer',
                'redirect_url' => '/company/dashboard',
            ],
        ]);
    }

    private function attemptLogin(LoginRequest $request, string $mode): array
    {
        $email = strtolower(trim($request->input('email')));
        $throttleKey = 'login-attempts:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $message = "Too many login attempts. Please try again in {$seconds} seconds.";

            throw new HttpResponseException(
                response()->json([
                    'message' => $message,
                    'errors' => ['email' => [$message]],
                ], 429, ['Retry-After' => (string) $seconds])
            );
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->status !== 'active' || ! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => 'You must activate your account first. Please check your email.',
            ]);
        }

        $organization = null;

        if ($mode === 'organization') {
            $organization = $user->organizations()
                ->wherePivot('role_in_org', 'admin')
                ->wherePivot('status', 'active')
                ->first();

            if (! $organization) {
                throw ValidationException::withMessages([
                    'email' => 'This account is not registered as an organization. Please use the individual login.',
                ]);
            }
        }

        $this->assertOrganizationIsApproved($user);

        $user->forceFill(['last_login_at' => now()])->save();

        $tokenResult = $user->createToken('auth_token');
        $this->authService->recordAuthSession($user, $tokenResult, $request);

        return [$user, $tokenResult, $organization];
    }

    private function assertOrganizationIsApproved(User $user): void
    {
        $blocking = $user->organizations()
            ->wherePivot('status', 'active')
            ->whereIn('verification_status', ['pending', 'rejected'])
            ->first();

        if (! $blocking) {
            return;
        }

        if ($blocking->verification_status === 'pending') {
            throw ValidationException::withMessages([
                'email' => 'Your organization is still pending approval. Please wait until it has been reviewed.',
            ]);
        }

        throw ValidationException::withMessages([
            'email' => 'Your organization registration was rejected. Please contact support for more information.',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();
        $decaySeconds = (int) config('password_reset.resend_interval_seconds', 60);

        $neutralResponse = fn () => response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset code has been sent.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);

        if (! $user) {
            return $neutralResponse();
        }

        $key = 'forgot-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return $neutralResponse();
        }

        RateLimiter::hit($key, $decaySeconds);
        $this->authService->sendPasswordResetOtp($user);

        return $neutralResponse();
    }

    public function resendPasswordReset(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();
        $decaySeconds = (int) config('password_reset.resend_interval_seconds', 60);

        $neutralResponse = fn () => response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset code has been sent.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);

        if (! $user) {
            return $neutralResponse();
        }

        if (! $this->authService->canResendPasswordReset($user->email)) {
            Log::info('Password reset resend blocked by cooldown', ['user_id' => $user->id]);

            return $neutralResponse();
        }

        Log::info('Password reset resend: sending new OTP', ['user_id' => $user->id]);
        $this->authService->sendPasswordResetOtp($user);

        return $neutralResponse();
    }

    public function verifyPasswordReset(VerifyPasswordResetRequest $request): JsonResponse
    {
        $isValid = $this->authService->verifyPasswordResetOtp(
            $request->input('email'),
            $request->input('otp')
        );

        if (! $isValid) {
            throw ValidationException::withMessages([
                'otp' => 'The reset code is invalid or has expired.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code verified. You can now set a new password.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $reset = $this->authService->resetPassword(
            $request->input('email'),
            $request->input('otp'),
            $request->input('password')
        );

        if (! $reset) {
            throw ValidationException::withMessages([
                'otp' => 'The reset code is invalid or has expired.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. You can now log in.',
            'data' => [
                'redirect_url' => '/login',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            AuthSession::where('user_id', $user->id)
                ->where('token_hash', $currentToken->token)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $currentToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data' => [
                'redirect_url' => '/',
            ],
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        AuthSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'You have been logged out from all devices.',
            'data' => [
                'redirect_url' => '/login',
            ],
        ]);
    }

    public function updateMe(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        foreach (['name', 'phone', 'locale'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Account details updated successfully.',
            'data' => [
                'user' => $this->accountPayload($user->fresh()),
            ],
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->password = (string) $request->input('password');
        $user->save();

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $currentHash = $current instanceof PersonalAccessToken ? $current->token : null;

        $others = $user->tokens();

        if ($currentId !== null) {
            $others->whereKeyNot($currentId);
        }

        $revoked = $others->count();
        $others->delete();

        $sessions = AuthSession::where('user_id', $user->id)->whereNull('revoked_at');

        if ($currentHash !== null) {
            $sessions->where('token_hash', '!=', $currentHash);
        }

        $sessions->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully. Other devices have been signed out.',
            'data' => [
                'revoked_sessions' => $revoked,
            ],
        ]);
    }

    private function accountPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
        ];
    }
}
