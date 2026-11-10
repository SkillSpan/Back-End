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

class ProfileController extends Controller
{
    private const COMPLETENESS_FIELDS = [
        'university_name',
        'student_university_number',
        'specialization',
        'academic_level',
        'career_status',
        'interests',
        'availability',
    ];

    public function store(StoreProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

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

    public function storeAvatar(Request $request): JsonResponse
    {
        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            return response()->json([
                'success' => false,
                'message' => 'No avatar image was uploaded.',
                'code' => 'PROFILE_AVATAR_INVALID',
            ], 422);
        }

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

    public function removeAvatar(Request $request): JsonResponse
    {
        $profile = $this->avatarService()->removeAvatar($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed.',
            'data' => $this->present($profile),
        ], 200);
    }

    public function avatar(Request $request): Response|JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if ($profile === null) {
            return $this->avatarMissing('PROFILE_NOT_FOUND', 'This learner has no profile yet.');
        }

        $binary = $this->avatarService()->avatarBinary($profile);

        if ($binary === null) {
            return $this->avatarMissing(
                'PROFILE_AVATAR_NOT_FOUND',
                'This learner has no profile photo.',
            );
        }

        return response($binary, 200, [
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function avatarMissing(string $code, string $message): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
        ], 404, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function avatarService(): StudentProfileService
    {
        return new StudentProfileService;
    }

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
