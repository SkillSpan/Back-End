<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetRequest;
use App\Http\Requests\Auth\VerifyRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    /**
     * POST /api/auth/register
     * Task: validate data, unique email, hash password, learner role,
     * academic status, terms/privacy timestamps, OTP, email service.
     */
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

    /**
     * POST /api/auth/register/organization
     * تسجيل خاص بالمؤسسات (شركة / جامعة / جهة تدريب) مع ملف إثبات إلزامي.
     */
    public function registerOrganization(RegisterOrganizationRequest $request): JsonResponse
    {
        $user = $this->authService->registerOrganization($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Your organization account has been created successfully. Your proof document will be reviewed and you need to verify your email.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => $user->status,
                'requires_verification' => true,
                'resend_available_at' => now()
                    ->addSeconds((int) config('verification.resend_interval_seconds', 60))
                    ->toIso8601String(),
            ],
        ], 201);
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
        $user = User::where('email', strtolower(trim($request->input('email'))))->firstOrFail();

        $key = 'resend-otp:' . $user->id;
        $decaySeconds = (int) config('verification.resend_interval_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'You cannot resend the code right now. Please wait before trying again.',
                'data' => [
                    'retry_after' => $seconds,
                ],
            ], 429);
        }

        RateLimiter::hit($key, $decaySeconds);
        $this->authService->resendOtp($user);

        return response()->json([
            'success' => true,
            'message' => 'A new verification code has been sent to your email.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $throttleKey = 'login-attempts:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 300); // قفل مؤقت 5 دقائق بعد المحاولة الفاشلة

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

        $this->assertOrganizationIsApproved($user);

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user->load(['roles', 'studentProfile']),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * POST /api/auth/login/organization
     * تسجيل دخول خاص بحسابات المؤسسات (شركة / جامعة / جهة تدريب) فقط.
     * يرفض أي حساب فرد (learner) حتى لو الإيميل وكلمة السر صحيحين.
     */
    public function loginOrganization(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $throttleKey = 'login-attempts:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
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

        $organization = $user->organizations()->first();

        if (! $organization) {
            throw ValidationException::withMessages([
                'email' => 'This account is not registered as an organization. Please use the individual login.',
            ]);
        }

        $this->assertOrganizationIsApproved($user);

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user->load('roles'),
                'organizations' => [$organization],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * يتحقق من حالة موافقة المؤسسة لأي يوزر مرتبط بمؤسسة، ويمنع الدخول
     * لو كانت pending أو rejected — بغض النظر عن أي endpoint استُخدم
     * لتسجيل الدخول (login أو login/organization). هذا يمنع الـ bypass
     * الأمني عبر استخدام الـ generic login endpoint.
     */
    private function assertOrganizationIsApproved(User $user): void
    {
        $organization = $user->organizations()->first();

        if (! $organization) {
            return;
        }

        if ($organization->verification_status === 'pending') {
            throw ValidationException::withMessages([
                'email' => 'Your organization is still pending approval. Please wait until it has been reviewed.',
            ]);
        }

        if ($organization->verification_status === 'rejected') {
            throw ValidationException::withMessages([
                'email' => 'Your organization registration was rejected. Please contact support for more information.',
            ]);
        }
    }

    /**
     * POST /api/auth/forgot-password
     * Task: validate email exists, generate OTP, email it, rate-limit resends.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();

        $neutralResponse = fn () => response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset code has been sent.',
        ]);

        if (! $user) {
            // نفس الرد المحايد بالضبط، بدون ما نكشف إذا الإيميل مسجل أصلاً.
            return $neutralResponse();
        }

        $key = 'forgot-password:' . $user->id;
        $decaySeconds = (int) config('password_reset.resend_interval_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            // نفس الرد المحايد أيضًا هون — منع كشف حتى عبر توقيت الاستجابة
            // أو رسائل مختلفة بحالة rate limiting.
            return $neutralResponse();
        }

        RateLimiter::hit($key, $decaySeconds);
        $this->authService->sendPasswordResetOtp($user);

        return $neutralResponse();
    }

    /**
     * POST /api/auth/forgot-password/resend
     * Task: re-send password reset OTP respecting the resend interval.
     */
    public function resendPasswordReset(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();

        $neutralResponse = fn () => response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset code has been sent.',
        ]);

        if (! $user) {
            return $neutralResponse();
        }

        if (! $this->authService->canResendPasswordReset($user->email)) {
            return $neutralResponse();
        }

        $this->authService->sendPasswordResetOtp($user);

        return $neutralResponse();
    }

    /**
     * POST /api/auth/forgot-password/verify
     * Task: check the OTP is valid WITHOUT resetting the password, so the
     * frontend can move to the "set new password" screen only when the
     * code is actually correct. Does not consume the code — the real
     * consumption still happens in resetPassword(). Shares the same
     * attempts counter as resetPassword() to keep brute-force protection
     * consistent no matter which endpoint the guesses go through.
     */
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

    /**
     * POST /api/auth/reset-password
     * Task: validate OTP against password_reset_tokens, update password.
     */
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
}
