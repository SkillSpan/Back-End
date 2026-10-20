<?php

namespace App\Http\Controllers\Api;

use App\Events\SkillDataChanged;
use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\CareerGoalHistory;
use App\Models\StudentProfile;
use App\Services\Profile\StudentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Learner profile endpoints (SRS PROF-01, PROF-04, PROF-05 / UC-02).
 * Every route sits behind 'auth:sanctum' + 'role:learner' (see routes/api.php),
 * so only an authenticated learner can create/read/update their own profile —
 * there is no {user} route param, the profile is always resolved from the
 * authenticated request, which also removes any IDOR risk.
 */
class ProfileController extends Controller
{
    /**
     * Fields that count toward profile completeness. Kept in one place so
     * store()/update() and the percentage calculation can't drift apart.
     * university/specialization are normalized Foreign Keys per the
     * learner-profile task list (not plain text).
     */
    private const COMPLETENESS_FIELDS = [
        'university_name',
        'student_university_number',
        'specialization',
        'academic_level',
        'career_status',
        'interests',
        'availability',
    ];

    /**
     * POST /api/v1/profile
     */
    public function store(StoreProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // A StudentProfile row is created at registration (see
        // AuthService::createStudentProfile()), so POST behaves as an
        // upsert: create when missing, otherwise update in place instead
        // of rejecting with a confusing conflict error.
        $existing = $user->studentProfile;

        if ($existing) {
            $existing->fill($data);
            if ($existing->visibility === null) {
                $existing->visibility = 'private';
            }
            $existing->completeness_percent = $this->calculateCompleteness($existing);
            $existing->save();

            return response()->json([
                'success' => true,
                'message' => 'Learner profile saved successfully.',
                'data' => $this->present($existing->fresh()),
            ], 200);
        }

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'university_name' => $data['university_name'],
            'student_university_number' => $data['student_university_number'],
            'specialization' => $data['specialization'],
            'academic_level' => $data['academic_level'],
            'expected_graduation' => $data['expected_graduation'] ?? null,
            'bio' => $data['bio'] ?? null,
            'career_status' => $data['career_status'] ?? null,
            'interests' => $data['interests'] ?? null,
            'availability' => $data['availability'] ?? null,
            'weekly_availability_hours' => $data['weekly_availability_hours'] ?? null,
            'preferred_work_type' => $data['preferred_work_type'] ?? null,
            'visibility' => $data['visibility'] ?? 'private',
            'consent_given' => true,
        ]);

        $profile->forceFill(['completeness_percent' => $this->calculateCompleteness($profile)])->save();

        return response()->json([
            'success' => true,
            'message' => 'Learner profile saved successfully.',
            'data' => $this->present($profile->fresh()),
        ], 201);
    }

    /**
     * GET /api/v1/profile
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'No profile exists for this account yet.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $this->present($profile),
        ]);
    }

    /**
     * PUT /api/v1/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'No profile exists for this account yet. Use POST /api/v1/profile to create one first.',
            ], 404);
        }

        $validated = $request->validated();
        $visibility = Arr::pull($validated, 'visibility');

        /*
         * US-INT-01 §24: a primary career-role change closes the old
         * CareerGoalHistory entry, opens a new one, and queues an
         * intelligence recalculation against the new role.
         */
        $oldCareerRoleId = $profile->primary_career_role_id;

        $profile->fill($validated);

        if ($visibility !== null) {
            $profile->forceFill(['visibility' => $visibility]);
        }
        if ($profile->visibility === null) {
            $profile->forceFill(['visibility' => 'private']);
        }
        $profile->forceFill(['completeness_percent' => $this->calculateCompleteness($profile)])->save();

        $careerRoleChanged = $oldCareerRoleId !== $profile->primary_career_role_id;

        if ($careerRoleChanged) {
            $this->recordCareerGoalChange($profile, $oldCareerRoleId);

            SkillDataChanged::dispatch(
                $profile->fresh(),
                'career_role_change',
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $this->present($profile->fresh()),
        ]);
    }

    /**
     * SRS PROF-05: "the system shall calculate profile completeness from
     * configured required and recommended fields." Re-normalized against
     * COMPLETENESS_FIELDS every time so it never drifts from the model.
     */
    private function calculateCompleteness(StudentProfile $profile): int
    {
        $filled = 0;

        foreach (self::COMPLETENESS_FIELDS as $field) {
            $value = $profile->{$field};

            $isFilled = is_array($value)
                ? $value !== []
                : $value !== null && $value !== '';

            if ($isFilled) {
                $filled++;
            }
        }

        return (int) round(($filled / count(self::COMPLETENESS_FIELDS)) * 100);
    }

    private function present(StudentProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'university_name' => $profile->university_name,
            'student_university_number' => $profile->student_university_number,
            'specialization' => $profile->specialization,
            'academic_level' => $profile->academic_level,
            'expected_graduation' => $profile->expected_graduation !== null ? (int) $profile->expected_graduation : null,
            'bio' => $profile->bio,
            'career_status' => $profile->career_status,
            'interests' => $profile->interests,
            'availability' => $profile->availability,
            'weekly_availability_hours' => $profile->weekly_availability_hours !== null
                ? (float) $profile->weekly_availability_hours
                : null,
            'preferred_work_type' => $profile->preferred_work_type,
            'visibility' => $profile->visibility,
            'completeness_percent' => $profile->completeness_percent,
            'enrollment_status' => $profile->enrollment_status,
            'graduation_status' => $profile->graduation_status,
            'graduation_date' => $profile->graduation_date?->toDateString(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
            'has_avatar' => $profile->hasAvatar(),
            'avatar_url' => $this->avatarService()->avatarUrl($profile),
            'avatar_updated_at' => $profile->avatar_updated_at?->toIso8601String(),
        ];
    }

    /**
     * POST /api/v1/profile/avatar
     *
     * The avatar is the learner's own face on the profile and in the support
     * thread, so it is stored and served exactly like the panel avatar: decoded,
     * centre-cropped, re-encoded to a fixed square — never trusted as-is.
     */
    public function storeAvatar(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            return response()->json([
                'success' => false,
                'message' => 'No avatar image was uploaded.',
                'code' => 'PROFILE_AVATAR_INVALID',
            ], 422);
        }

        // PHP-level upload errors (truncated mid-flight because the photo was
        // bigger than `upload_max_filesize`, the connection dropped, the temp
        // dir was full, …) are mapped to a clear message here. Without this
        // branch the service would still detect the failure — but `$file->get()`
        // on an errored upload returns a partial / empty payload that
        // `imagecreatefromstring` then rejects with the opaque "could not be
        // processed" message. Telling the caller what PHP rejected and why
        // (vs. silently re-encoding the error as a downstream GD failure) is
        // the fix that came out of the dashboard bug.
        if (! $file->isValid()) {
            $maxKb = (int) config('services.profile.avatar_max_upload_kb', 4096);

            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                    'The photo is too large to upload (limit %d KB). Please pick a smaller image.',
                    $maxKb,
                ),
                UPLOAD_ERR_PARTIAL => 'The upload was cut off before it finished. Please try again.',
                UPLOAD_ERR_NO_FILE => 'No photo was received. Please pick a file and try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Please try again in a moment.',
                UPLOAD_ERR_EXTENSION => 'The photo was rejected by the server. Please try a JPEG, PNG, WebP or GIF.',
                default => 'The photo could not be uploaded. Please try a JPEG, PNG, WebP or GIF under '
                    .$maxKb.' KB.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => 'PROFILE_AVATAR_INVALID',
            ], 422);
        }

        // Bytes can only be checked on a valid upload — for an INI_SIZE failure
        // $file->getSize() returns the truncated size, not the intended one,
        // so this guard would either be a no-op or mislead the caller.
        $maxKb = (int) config('services.profile.avatar_max_upload_kb', 4096);

        if (($file->getSize() / 1024) > $maxKb) {
            return response()->json([
                'success' => false,
                'message' => 'The image is larger than the '.$maxKb.' KB limit.',
                'code' => 'PROFILE_AVATAR_INVALID',
            ], 422);
        }

        try {
            $profile = $this->avatarService()->storeAvatar($request->user(), $file);
        } catch (ReadinessException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->codeName,
            ], $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated.',
            'data' => $this->present($profile),
        ], 200);
    }

    /**
     * DELETE /api/v1/profile/avatar
     *
     * Back to initials. Idempotent: removing an absent avatar is a success.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        $profile = $this->avatarService()->removeAvatar($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed.',
            'data' => $this->present($profile),
        ], 200);
    }

    /**
     * GET /api/v1/profile/avatar
     *
     * The raw image bytes for the authenticated learner's own avatar. Served
     * from a stable URL (versioned by `avatar_updated_at`) so the browser can
     * cache it; 404 when there is none.
     */
    public function avatar(Request $request): Response
    {
        $profile = $request->user()->studentProfile;

        if ($profile === null) {
            abort(404);
        }

        $binary = $this->avatarService()->avatarBinary($profile);

        if ($binary === null) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function avatarService(): StudentProfileService
    {
        return new StudentProfileService;
    }

    /**
     * US-INT-01 §24 — track career-goal history: close the previous
     * goal entry (replaced_at) and open a new one for the current role.
     * The CareerGoalHistory table pre-exists; this finally writes it.
     */
    private function recordCareerGoalChange(StudentProfile $profile, ?int $oldCareerRoleId): void
    {
        if ($oldCareerRoleId !== null) {
            $profile->careerGoalHistory()
                ->whereNull('replaced_at')
                ->where('career_role_id', $oldCareerRoleId)
                ->update(['replaced_at' => now()]);
        }

        CareerGoalHistory::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $profile->primary_career_role_id,
            'career_role_version' => $profile->primaryCareerRole?->version ?? 1,
            'set_at' => now(),
            'replaced_at' => null,
        ]);
    }
}
