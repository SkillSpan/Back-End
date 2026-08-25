<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\StudentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     */
    private const COMPLETENESS_FIELDS = [
        'education',
        'specialization',
        'career_status',
        'interests',
        'availability',
        'preferred_work_type',
    ];

    /**
     * POST /api/v1/profile
     */
    public function store(StoreProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->studentProfile) {
            // A profile already exists (created at registration — see
            // AuthService::createStudentProfile()). POST only creates;
            // point the caller at PUT instead of silently overwriting it.
            return response()->json([
                'success' => false,
                'message' => 'A profile already exists for this account. Use PUT /api/v1/profile to update it.',
            ], 409);
        }

        $data = $request->validated();

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'education' => $data['education'],
            'specialization' => $data['specialization'],
            'career_status' => $data['career_status'] ?? null,
            'interests' => $data['interests'] ?? null,
            'availability' => $data['availability'] ?? null,
            'preferred_work_type' => $data['preferred_work_type'] ?? null,
            'visibility' => $data['visibility'] ?? 'private',
            'consent_given' => true,
        ]);

        $profile->update(['completeness_percent' => $this->calculateCompleteness($profile)]);

        return response()->json([
            'success' => true,
            'message' => 'Learner profile created successfully.',
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

        $profile->fill($request->validated());
        $profile->completeness_percent = $this->calculateCompleteness($profile);
        $profile->save();

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
            'education' => $profile->education,
            'specialization' => $profile->specialization,
            'career_status' => $profile->career_status,
            'interests' => $profile->interests,
            'availability' => $profile->availability,
            'preferred_work_type' => $profile->preferred_work_type,
            'visibility' => $profile->visibility,
            'completeness_percent' => $profile->completeness_percent,
            'enrollment_status' => $profile->enrollment_status,
            'graduation_status' => $profile->graduation_status,
            'graduation_date' => $profile->graduation_date?->toDateString(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }
}
