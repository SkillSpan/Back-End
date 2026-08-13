<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
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
            'message' => 'تم إنشاء الحساب بنجاح. يرجى التحقق من بريدك الإلكتروني.',
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
            'message' => 'تم إنشاء حساب المؤسسة بنجاح. سيتم مراجعة ملف الإثبات والتحقق من بريدك الإلكتروني.',
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
                'otp' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تفعيل حسابك بنجاح. يمكنك الآن تسجيل الدخول.',
            'data' => [
                'redirect_url' => '/login',
            ],
        ]);
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->firstOrFail();

        $key = 'resend-otp:' . $user->id;
        $decaySeconds = (int) config('verification.resend_interval_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إعادة الإرسال الآن. يرجى الانتظار.',
                'data' => [
                    'retry_after' => $seconds,
                ],
            ], 429);
        }

        RateLimiter::hit($key, $decaySeconds);
        $this->authService->resendOtp($user);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال رمز تحقق جديد إلى بريدك الإلكتروني.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        if ($user->status !== 'active' || ! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => 'يجب تفعيل حسابك أولاً. يرجى التحقق من بريدك الإلكتروني.',
            ]);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح.',
            'data' => [
                'user' => $user->load(['roles', 'studentProfile']),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     * Task: validate email exists, generate OTP, email it, rate-limit resends.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->firstOrFail();

        $key = 'forgot-password:' . $user->id;
        $decaySeconds = (int) config('password_reset.resend_interval_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إعادة الإرسال الآن. يرجى الانتظار.',
                'data' => [
                    'retry_after' => $seconds,
                ],
            ], 429);
        }

        RateLimiter::hit($key, $decaySeconds);
        $this->authService->sendPasswordResetOtp($user);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/auth/forgot-password/resend
     * Task: re-send password reset OTP respecting the resend interval.
     */
    public function resendPasswordReset(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->firstOrFail();

        if (! $this->authService->canResendPasswordReset($user->email)) {
            $availableAt = $this->authService->passwordResetResendAvailableAt($user->email);

            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إعادة الإرسال الآن. يرجى الانتظار.',
                'data' => [
                    'retry_after' => $availableAt ? now()->diffInSeconds($availableAt) : null,
                ],
            ], 429);
        }

        $this->authService->sendPasswordResetOtp($user);

        $decaySeconds = (int) config('password_reset.resend_interval_seconds', 60);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.',
            'data' => [
                'resend_available_at' => now()->addSeconds($decaySeconds)->toIso8601String(),
            ],
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
                'otp' => 'رمز إعادة التعيين غير صحيح أو منتهي الصلاحية.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.',
            'data' => [
                'redirect_url' => '/login',
            ],
        ]);
    }
}
