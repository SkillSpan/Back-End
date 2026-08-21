<?php

namespace App\Services;

use App\Models\AccountVerification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\UploadedFile;
use App\Models\User;
use App\Notifications\AccountVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            Log::error('Individual registration failed: ' . $e->getMessage(), [
                'email' => $validatedData['email'] ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * POST /api/auth/register/organization
     * تسجيل خاص بالمؤسسات (شركة / جامعة / جهة تدريب). يتطلب ملف إثبات
     * إلزامي ويبقى الحساب/الملف بحالة pending لحين مراجعة الإدارة.
     *
     * ملاحظة: حسابات المؤسسات ما عاد تمر بخطوة تحقق OTP على الإيميل —
     * البوابة الوحيدة هلق هي موافقة/رفض الأدمن على المؤسسة نفسها
     * (verification_status)، عبر assertOrganizationIsApproved() بالكنترولر.
     * لهيك بنفعّل الحساب مباشرة هون بدل ما ننتظر تحقق إيميل غير موجود أصلاً.
     */
    public function registerOrganization(array $validatedData): User
    {
        try {
            DB::beginTransaction();

            $user = $this->createUser($validatedData);
            $user->update([
                'email_verified_at' => now(),
                'status' => 'active',
            ]);

            $organization = $this->createOrganization($user, $validatedData);
            $this->uploadProofFile($user, $organization, $validatedData['proof_file']);
            $this->createOrganizationMember($user, $organization);

            DB::commit();

            return $user->fresh(['roles', 'organizations']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Organization registration failed: ' . $e->getMessage(), [
                'email' => $validatedData['email'] ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function createUser(array $data): User
    {
        return User::create([
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

        StudentProfile::create([
            'user_id' => $user->id,
            'education' => $data['education'] ?? null,
            'specialization' => $data['specialization'] ?? null,
            'career_status' => $careerStatus,
            'enrollment_status' => $enrollmentStatus,
            'graduation_status' => $graduationStatus,
            'visibility' => 'private',
            'consent_given' => true,
            'completeness_percent' => 15,
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
        $organization = Organization::create([
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
        $path = $file->store('proofs/' . $organization->id, 'local');

        UploadedFile::create([
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
        OrganizationMember::create([
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

        AccountVerification::create([
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

            $user = User::where('email', strtolower(trim($email)))->firstOrFail();

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
                $verification->update(['decision' => 'rejected', 'decided_at' => now()]);
                DB::commit();

                return false;
            }

            if (! Hash::check($otp, $verification->challenge_state)) {
                $verification->increment('attempts');
                DB::commit();

                return false;
            }

            $verification->update([
                'decision' => 'approved',
                'decided_at' => now(),
            ]);

            $user->update([
                'email_verified_at' => now(),
                'status' => 'active',
            ]);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Verification failed: ' . $e->getMessage(), ['email' => $email]);
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

    public function canResendOtp(User $user): bool
    {
        $lastVerification = AccountVerification::where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $lastVerification) {
            return true;
        }

        $resendInterval = (int) config('verification.resend_interval_seconds', 60);

        return $lastVerification->created_at->diffInSeconds(now()) >= $resendInterval;
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

        return now()->diffInSeconds($record->created_at) >= $resendInterval;
    }

    public function passwordResetResendAvailableAt(string $email): ?Carbon
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (! $user) {
            return null;
        }

        $record = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if (! $record || ! $record->created_at) {
            return null;
        }

        $resendInterval = (int) config('password_reset.resend_interval_seconds', 60);
        $availableAt = Carbon::parse($record->created_at)->addSeconds($resendInterval);

        return $availableAt->isFuture() ? $availableAt : null;
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
     * الإيميل ما عاد مطلوب هون: المستخدم أصلاً أكّد الإيميل والـ OTP في
     * خطوة forgot-password/verify السابقة، فما في داعي يدخله مرة ثانية.
     * بدل الدوران بالـ user_id (اللي كان جاي من الإيميل)، بندور على كل
     * الـ tokens الفعّالة (مش منتهية ومش مستهلكة) ونعمل Hash::check على
     * كل وحدة لحد ما نلاقي المطابقة، وناخد المستخدم من الـ token نفسه.
     */
    public function resetPassword(string $otp, string $newPassword): bool
    {
        try {
            DB::beginTransaction();

            $maxAttempts = (int) config('verification.max_attempts', 5);

            $candidates = DB::table('password_reset_tokens')
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', $maxAttempts)
                ->orderByDesc('created_at')
                ->get();

            $record = $candidates->first(
                fn ($candidate) => Hash::check($otp, $candidate->token_hash)
            );

            if (! $record) {
                DB::commit();

                return false;
            }

            $user = User::find($record->user_id);

            if (! $user) {
                DB::rollBack();

                return false;
            }

            $user->update(['password' => $newPassword]);

            DB::table('password_reset_tokens')
                ->where('id', $record->id)
                ->update(['consumed_at' => now()]);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Password reset failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
