<?php

namespace App\Services;

use App\Models\AccountVerification;
use App\Models\AuthSession;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\UploadedFile;
use App\Models\User;
use App\Notifications\AccountVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

class AuthService
{
    /**
     * POST /api/auth/register
     * تسجيل الأفراد (Learner) فقط.
     */
    public function registerIndividual(array $validatedData): User
    {
        try {
            DB::beginTransaction();

            $user = $this->createUser($validatedData);
            $this->assignRole($user, 'individual');
            $this->createStudentProfile($user, $validatedData);

            $otp = $this->generateOtp($user);
            $user->notify(new AccountVerificationNotification($otp));

            DB::commit();

            return $user->fresh(['roles', 'studentProfile']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Individual registration failed: '.$e->getMessage(), [
                'email' => $validatedData['email'] ?? 'unknown',
            ]);
            throw $e;
        }
    }

    /**
     * POST /api/auth/register/organization
     * تسجيل خاص بالمؤسسات (شركة / جامعة / جهة تدريب). يتطلب ملف إثبات
     * إلزامي. لا يوجد OTP لهاد الفلو — اليوزر بينعمله verify تلقائيًا،
     * لكن تسجيل الدخول يضل ممنوع لحد ما الإدارة توافق على المؤسسة
     * (Organization::verification_status لسا pending لحد المراجعة).
     */
    public function registerOrganization(array $validatedData): User
    {
        try {
            DB::beginTransaction();

            $user = $this->createUser($validatedData);
            $organization = $this->createOrganization($user, $validatedData);
            $this->uploadProofFile($user, $organization, $validatedData['proof_file']);
            $this->createOrganizationMember($user, $organization);

            // تسجيل المؤسسات: لا يوجد إرسال OTP هون. البريد يُعتبر
            // موثّق تلقائيًا لأنه أصلاً في-review يدوي من الإدارة عبر
            // Organization::verification_status (pending/approved/rejected)
            // و assertOrganizationIsApproved() بتمنع تسجيل الدخول لحد
            // ما تتم الموافقة. طبقة الـ OTP كانت بوابة إضافية زائدة
            // كانت عم توقف تسجيل الشركة، فتم إلغاؤها لهاد الفلو تحديدًا.
            $user->forceFill([
                'email_verified_at' => now(),
                'status' => 'active',
            ])->save();

            DB::commit();

            return $user->fresh(['roles', 'organizations']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Organization registration failed: '.$e->getMessage(), [
                'email' => $validatedData['email'] ?? 'unknown',
            ]);
            throw $e;
        }
    }

    /**
     * POST /api/auth/google
     * Authenticate or register an individual user using a verified Google ID token.
     *
     * @return array{user: User, token: string}
     */
    public function loginWithGoogle(
        string $credential,
        bool $termsAccepted = false,
        bool $privacyAccepted = false,
        ?Request $request = null
    ): array {
        try {
            $clientId = config('services.google.client_id');

            if (! $clientId) {
                Log::error('Google Client ID is not configured.');

                throw ValidationException::withMessages([
                    'credential' => 'Google authentication is not configured.',
                ]);
            }

            $payload = $this->verifyGoogleIdToken($credential, $clientId);

            if (! $payload) {
                Log::error('Google verifyIdToken returned falsy without throwing', [
                    'client_id_configured' => $clientId,
                ]);

                throw ValidationException::withMessages([
                    'credential' => 'Invalid or expired Google credential.',
                ]);
            }

            $googleId = $payload['sub'] ?? null;
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $name = trim((string) ($payload['name'] ?? ''));

            if (! $googleId || ! $email) {
                throw ValidationException::withMessages([
                    'credential' => 'Google account information is incomplete.',
                ]);
            }

            if (! ($payload['email_verified'] ?? false)) {
                throw ValidationException::withMessages([
                    'credential' => 'Your Google email address must be verified.',
                ]);
            }

            DB::beginTransaction();

            /*
             * First try to find the user by Google ID.
             */
            $user = User::where('google_id', $googleId)->first();

            /*
             * If the Google ID is not linked yet, check the email.
             * This allows an existing SkillSpan account to link its Google account.
             */
            if (! $user) {
                $user = User::where('email', $email)->first();
            }

            /*
             * Existing SkillSpan account.
             */
            if ($user) {
                if ($user->status !== 'active' || ! $user->email_verified_at) {
                    DB::rollBack();

                    throw ValidationException::withMessages([
                        'credential' => 'You must activate your SkillSpan account first.',
                    ]);
                }

                /*
                 * Link Google to an existing account if it is not linked yet.
                 */
                if (! $user->google_id) {
                    $user->google_id = $googleId;
                } elseif ($user->google_id !== $googleId) {
                    DB::rollBack();

                    throw ValidationException::withMessages([
                        'credential' => 'This Google account is not linked to this user.',
                    ]);
                }

                $this->assertOrganizationIsApproved($user);

                $user->last_login_at = now();
                $user->save();

                $tokenResult = $user->createToken('auth_token');
                $this->recordAuthSession($user, $tokenResult, $request);

                DB::commit();

                return [
                    'user' => $user->fresh(['roles', 'studentProfile']),
                    'token' => $tokenResult->plainTextToken,
                    'created' => false,
                ];
            }

            /*
             * New Google user.
             */
            if (! $termsAccepted || ! $privacyAccepted) {
                DB::rollBack();

                throw ValidationException::withMessages([
                    'terms_accepted' => 'You must accept the Terms and Conditions.',
                    'privacy_accepted' => 'You must accept the Privacy Policy.',
                ]);
            }

            /*
             * Reuse the existing individual-user creation logic.
             * A random password is generated because Google handles authentication.
             */
            $user = $this->createUser([
                'name' => $name !== '' ? $name : str()->before($email, '@'),
                'email' => $email,
                'password' => str()->random(64),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ]);

            /*
             * Google already verified the user's email.
             * Therefore no OTP verification is required.
             */
            $user->forceFill([
                'google_id' => $googleId,
                'status' => 'active',
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ])->save();

            /*
             * Reuse the existing SkillSpan individual-user setup.
             */
            $this->assignRole($user, 'individual');
            $this->createStudentProfile($user, []);

            $tokenResult = $user->createToken('auth_token');
            $this->recordAuthSession($user, $tokenResult, $request);

            DB::commit();

            return [
                'user' => $user->fresh(['roles', 'studentProfile']),
                'token' => $tokenResult->plainTextToken,
                'created' => true,
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Google login failed: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Network boundary for Google ID token verification — kept as its own
     * method (instead of inlined in loginWithGoogle()) so tests can stub
     * it directly instead of hitting Google's real servers with a fake
     * token (see tests/Feature/Auth/GoogleLoginTest.php::mockVerifiedToken()).
     *
     * google/apiclient's Verify::verifyIdToken() swallows
     * ExpiredException|SignatureInvalidException|DomainException
     * internally and just returns false, so we had zero visibility into
     * *why* a fresh, audience-matching token was still being rejected.
     * This try/catch — placed around the call, not inside the library —
     * surfaces the real exception class + message in the logs instead of
     * guessing.
     *
     * @return array<string, mixed>|false
     */
    protected function verifyGoogleIdToken(string $credential, string $clientId): array|false
    {
        $client = new Google_Client([
            'client_id' => $clientId,
        ]);

        try {
            return $client->verifyIdToken($credential);
        } catch (Throwable $e) {
            Log::error('Google verifyIdToken threw an exception', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * يتحقق من حالة موافقة المؤسسة لأي يوزر مرتبط بمؤسسة، بنفس منطق
     * AuthController::assertOrganizationIsApproved() — مطلوب هون كمان
     * لأن تسجيل الدخول عبر Google لازم يمر بنفس فحص الموافقة، وإلا
     * صار مسار bypass لحساب مؤسسة pending/rejected عبر Google بدل
     * الـ login العادي.
     */
    private function assertOrganizationIsApproved(User $user): void
    {
        $organization = $user->organizations()->first();

        if (! $organization) {
            return;
        }

        if ($organization->verification_status === 'pending') {
            throw ValidationException::withMessages([
                'credential' => 'Your organization is still pending approval. Please wait until it has been reviewed.',
            ]);
        }

        if ($organization->verification_status === 'rejected') {
            throw ValidationException::withMessages([
                'credential' => 'Your organization registration was rejected. Please contact support for more information.',
            ]);
        }
    }

    private function createUser(array $data): User
    {
        return User::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'locale' => $data['locale'] ?? 'en',
            // password cast ('hashed') will hash; Hash::make is also fine with isHashed guard
            'password' => $data['password'],
            'status' => 'pending',
            'email_verified_at' => null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
    }

    private function assignRole(User $user, string $userType): void
    {
        $roleSlug = match ($userType) {
            'individual' => 'learner',
            'organization' => 'company_admin',
            default => 'learner',
        };

        $role = Role::firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => ucfirst(str_replace('_', ' ', $roleSlug)),
                'description' => 'Auto-generated role',
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $role->id => ['organization_id' => null],
        ]);
    }

    private function createStudentProfile(User $user, array $data): void
    {
        $enrollmentStatus = 'enrolled';
        $graduationStatus = false;
        $careerStatus = $data['career_status'] ?? null;

        if (! empty($data['academic_status'])) {
            [$enrollmentStatus, $careerStatus, $graduationStatus] = $this->mapAcademicStatus($data['academic_status']);
        }

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'career_status' => $careerStatus,
            'enrollment_status' => $enrollmentStatus,
            'graduation_status' => $graduationStatus,
            'visibility' => 'private',
            'consent_given' => true,
            // SRS PROF-05: completeness is derived from the configured
            // field list — an untouched profile has none filled, so 0.
            // (A hardcoded 15 here outranked genuinely fuller profiles
            // until the first update recomputed it.)
            'completeness_percent' => 0,
        ]);
    }

    /**
     * @return array{0: string, 1: string|null, 2: bool}
     */
    private function mapAcademicStatus(string $academicStatus): array
    {
        return match ($academicStatus) {
            'enrolled', 'student' => ['enrolled', 'student', false],
            'graduated', 'graduate' => ['graduated', 'graduate', true],
            'on_leave' => ['on_leave', 'student', false],
            'looking_for_job' => ['graduated', 'looking_for_job', true],
            'employed' => ['graduated', 'employed', true],
            default => ['enrolled', $academicStatus, false],
        };
    }

    private function createOrganization(User $user, array $data): Organization
    {
        $organization = Organization::forceCreate([
            'name' => $data['organization_name'],
            'type' => $data['organization_type'],
            'verification_status' => 'pending',
            'contact_email' => $data['organization_contact_email'],
            'contact_phone' => $data['organization_contact_phone'] ?? null,
            'website' => $data['organization_website'] ?? null,
            'description' => $data['organization_description'] ?? null,
            'industry' => $data['organization_industry'] ?? null,
            'company_size' => $data['organization_company_size'] ?? null,
            'country' => $data['organization_country'] ?? null,
            'city' => $data['organization_city'] ?? null,
            'address' => $data['organization_address'] ?? null,
            'postal_code' => $data['organization_postal_code'] ?? null,
        ]);

        $roleSlug = match ($data['organization_type']) {
            'university' => 'university_admin',
            default => 'company_admin',
        };

        $role = Role::firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => ucfirst(str_replace('_', ' ', $roleSlug)),
                'description' => 'Organization administrator',
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $role->id => ['organization_id' => $organization->id],
        ]);

        return $organization;
    }

    private function uploadProofFile(User $user, Organization $organization, HttpUploadedFile $file): void
    {
        $path = $file->store('proofs/'.$organization->id, 'local');

        UploadedFile::forceCreate([
            'user_id' => $user->id,
            'fileable_type' => Organization::class,
            'fileable_id' => $organization->id,
            'type' => 'certificate',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'pending',
        ]);
    }

    private function createOrganizationMember(User $user, Organization $organization): void
    {
        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);
    }

    private function generateOtp(User $user): string
    {
        $digits = (int) config('verification.otp_digits', 6);
        $max = (10 ** $digits) - 1;
        $otp = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

        AccountVerification::forceCreate([
            'user_id' => $user->id,
            'organization_id' => null,
            'channel' => 'email',
            'challenge_state' => Hash::make($otp),
            'expires_at' => now()->addMinutes((int) config('verification.otp_lifetime_minutes', 10)),
            'decision' => 'pending',
        ]);

        return $otp;
    }

    public function verifyOtp(string $email, string $otp): bool
    {
        try {
            DB::beginTransaction();

            // first() + false — NOT firstOrFail(): a 404 for unknown
            // addresses would distinguish them from wrong-OTP failures
            // and leak which emails are registered.
            $user = User::where('email', strtolower(trim($email)))->first();

            if (! $user) {
                DB::rollBack();

                return false;
            }

            $verification = AccountVerification::where('user_id', $user->id)
                ->where('decision', 'pending')
                ->latest()
                ->first();

            if (! $verification || $verification->expires_at < now()) {
                DB::rollBack();

                return false;
            }

            $maxAttempts = (int) config('verification.max_attempts', 5);

            if ($verification->attempts >= $maxAttempts) {
                // تجاوز الحد الأقصى للمحاولات: نرفض الطلب بشكل نهائي حتى لو
                // الرمز صحيح، لمنع الـ brute-force. المستخدم لازم يطلب
                // رمز جديد عبر resend-otp.
                $verification->forceFill(['decision' => 'rejected', 'decided_at' => now()])->save();
                DB::commit();

                return false;
            }

            if (! Hash::check($otp, $verification->challenge_state)) {
                $verification->increment('attempts');
                DB::commit();

                return false;
            }

            $verification->forceFill([
                'decision' => 'approved',
                'decided_at' => now(),
            ])->save();

            $user->forceFill([
                'email_verified_at' => now(),
                'status' => 'active',
            ])->save();

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Verification failed: '.$e->getMessage(), ['email' => $email]);
            throw $e;
        }
    }

    public function resendOtp(User $user): void
    {
        AccountVerification::where('user_id', $user->id)
            ->where('decision', 'pending')
            ->update([
                'decision' => 'rejected',
                'reason' => 'superseded_by_resend',
                'decided_at' => now(),
            ]);

        $otp = $this->generateOtp($user);
        $user->notify(new AccountVerificationNotification($otp));
    }

    public function sendPasswordResetOtp(User $user): void
    {
        $digits = (int) config('password_reset.otp_digits', 6);
        $max = (10 ** $digits) - 1;
        $otp = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

        $lifetimeMinutes = (int) config('password_reset.otp_lifetime_minutes', 10);

        // أي طلبات سابقة لم تُستهلك تُعتبر منتهية بمجرد توليد رمز جديد.
        DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        DB::table('password_reset_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes($lifetimeMinutes),
            'consumed_at' => null,
            'created_at' => now(),
        ]);

        $user->notify(new PasswordResetNotification($otp));
    }

    public function canResendPasswordReset(string $email): bool
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (! $user) {
            return true;
        }

        $record = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if (! $record || ! $record->created_at) {
            return true;
        }

        $resendInterval = (int) config('password_reset.resend_interval_seconds', 60);

        // NOT now()->diffInSeconds($record->created_at): Carbon 3 made that
        // diff SIGNED, so an older record yields a negative number and this
        // check could never pass again — resend was silently dead forever.
        // (DB::table rows carry raw string dates, hence the explicit parse.)
        $cooldownEndsAt = Carbon::parse($record->created_at)
            ->addSeconds($resendInterval);

        return now()->greaterThanOrEqualTo($cooldownEndsAt);
    }

    /**
     * تتحقق من صحة كود استرجاع كلمة المرور فقط، بدون استهلاكه وبدون تغيير
     * أي كلمة مرور. مخصصة لزر "Verify code" بالفرونت إند، عشان نعرف قبل
     * ما نعرض شاشة "كلمة مرور جديدة" إذا كان الكود صح أصلاً.
     *
     * بتشارك نفس عداد attempts مع resetPassword() (نفس السطر بالجدول)،
     * فمحاولات التخمين هون بتُحسب على نفس الحد الأقصى، ومحاولات reset-password
     * المباشرة برضه بتُحسب هون — ما في طريقة تلف على العداد.
     */
    public function verifyPasswordResetOtp(string $email, string $otp): bool
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (! $user) {
            return false;
        }

        $record = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $record || now()->greaterThan($record->expires_at)) {
            return false;
        }

        $maxAttempts = (int) config('verification.max_attempts', 5);

        if ($record->attempts >= $maxAttempts) {
            // تجاوز الحد الأقصى: نرفض حتى لو الكود صح فعليًا، لمنع
            // brute-force. المستخدم لازم يطلب كود جديد عبر forgot-password/resend.
            return false;
        }

        if (! Hash::check($otp, $record->token_hash)) {
            DB::table('password_reset_tokens')
                ->where('id', $record->id)
                ->increment('attempts');

            return false;
        }

        // الكود صح: ما بنستهلكه (consumed_at يضل null) لأن الاستهلاك
        // الفعلي بيصير بس عند resetPassword() لما يتحدد كلمة مرور جديدة.
        return true;
    }

    /**
     * الإيميل مطلوب هون (زي verifyPasswordResetOtp تمامًا) — لأنه بدونه
     * كنا مضطرين نمسح Hash::check على كل الـ tokens الفعّالة بالنظام
     * كله لحد ما نلاقي تطابق. هاد كان فيه مشكلتين حقيقيتين:
     *   1. أمان: تخمين عشوائي لكود مكون من 6 أرقام بيتفحص مقابل كل
     *      المستخدمين الفعّالين مرة وحدة، فكل ما زاد عدد طلبات الاسترجاع
     *      المتزامنة، زادت فرصة التخمين العرضي (بدل 1/1,000,000 لمستخدم
     *      واحد، تصير تقريبًا N/1,000,000 لو في N طلب فعّال بنفس الوقت).
     *   2. أداء: Hash::check (bcrypt) عملية بطيئة عمدًا؛ تكرارها على كل
     *      سجل فعّال بكل طلب reset-password بيفتح باب DoS واضح.
     * رجّعناها تاخد الإيميل زي الأول، فالبحث يصير مباشر على مستخدم واحد
     * بس (نفس منطق verifyPasswordResetOtp تمامًا).
     */
    public function resetPassword(string $email, string $otp, string $newPassword): bool
    {
        try {
            DB::beginTransaction();

            $user = User::where('email', strtolower(trim($email)))->first();

            if (! $user) {
                DB::rollBack();

                return false;
            }

            $record = DB::table('password_reset_tokens')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->latest('created_at')
                ->first();

            if (! $record) {
                DB::rollBack();

                return false;
            }

            if (now()->greaterThan($record->expires_at)) {
                DB::rollBack();

                return false;
            }

            $maxAttempts = (int) config('verification.max_attempts', 5);

            if ($record->attempts >= $maxAttempts) {
                DB::rollBack();

                return false;
            }

            if (! Hash::check($otp, $record->token_hash)) {
                DB::table('password_reset_tokens')
                    ->where('id', $record->id)
                    ->increment('attempts');

                DB::commit();

                return false;
            }

            $user->update(['password' => $newPassword]);

            // AUTH-SEC: changing the password must invalidate every existing
            // session — a token leaked before the password change stays valid
            // otherwise. This revokes the Sanctum tokens (forcing re-login
            // everywhere) and marks the matching auth_sessions rows so the
            // revocation stays auditable. This is NOT a periodic expiry, so it
            // does not nag the user: it only logs the account out when their
            // password actually changes, which is the desired security behavior.
            $user->tokens()->delete();

            AuthSession::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            DB::table('password_reset_tokens')
                ->where('id', $record->id)
                ->update(['consumed_at' => now()]);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Password reset failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * US-AUTH-06 flagged that a token was being issued on login without a
     * matching row being written to auth_sessions, which meant logout /
     * logout-all had nothing to mark as revoked. This is the single place
     * that closes that gap — every code path that calls
     * $user->createToken() (login, login/organization, login/google) must
     * also call this right after, so AuthController::logout() /
     * logoutAll() always have a record to update.
     *
     * token_hash intentionally stores the exact same value Sanctum stores
     * on personal_access_tokens.token (hash('sha256', $plainTextToken)),
     * so a later request's $user->currentAccessToken()->token can be
     * matched straight back to the row created here — no extra column or
     * lookup table needed.
     */
    public function recordAuthSession(User $user, NewAccessToken $tokenResult, ?Request $request = null): void
    {
        AuthSession::forceCreate([
            'user_id' => $user->id,
            'token_hash' => $tokenResult->accessToken->token,
            'device' => $this->guessDevice($request?->userAgent()),
            'user_agent' => $request?->userAgent(),
            'ip_address' => $request?->ip(),
            'result' => 'success',
            'issued_at' => now(),
            'expires_at' => $tokenResult->accessToken->expires_at,
        ]);
    }

    /**
     * Best-effort device label from the User-Agent header, only used for
     * the auth_sessions.device audit column — never for any access-control
     * decision, so a wrong guess here has no security impact.
     */
    private function guessDevice(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/tablet|ipad/i', $userAgent) => 'tablet',
            (bool) preg_match('/mobile|android|iphone/i', $userAgent) => 'mobile',
            default => 'desktop',
        };
    }
}
