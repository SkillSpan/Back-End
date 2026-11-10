<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Support\ReferenceCache;
use App\Support\ReferenceResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SkillsController extends Controller
{
    public function taxonomy(Request $request)
    {
        $skills = Cache::remember(
            ReferenceCache::SKILLS_TAXONOMY,
            ReferenceCache::TTL_SECONDS,
            fn (): array => Skill::with(['aliases', 'parent', 'children', 'careerRoles'])
                ->where('status', 'active')
                ->get()
                ->toArray(),
        );

        return ReferenceResponse::make(
            'Skills taxonomy retrieved successfully.',
            $skills,
            $request,
            shared: false,
        );
    }

    public function matrix(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $learnerId = $isAdmin
            ? $request->query('learner_id')
            : $user->id;

        $careerRoleId = $request->query('career_role_id');

        $query = LearnerSkill::with(['skill']);

        if ($learnerId) {
            $query->where('learner_id', $learnerId);
        }

        if ($careerRoleId) {
            $query->whereHas('skill', function ($q) use ($careerRoleId) {
                $q->whereHas('careerRoles', function ($q) use ($careerRoleId) {
                    $q->where('career_role_id', $careerRoleId);
                });
            });
        }

        $learnerSkills = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Skill matrix retrieved successfully.',
            'data' => $learnerSkills,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:users,id',
            'skill_id' => 'required|exists:skills,id',
            'level' => 'required|numeric|between:0.00,5.00',
            'confidence_score' => 'required|numeric|between:0.00,100.00',
            'source_type' => 'sometimes|required|string',
            'algorithm_version' => 'sometimes|required|string',
            'configuration_version' => 'sometimes|required|string',
            'source_contributions' => 'sometimes|array',
        ]);

        $user = $request->user();

        if (! $user->hasRole('admin') && (int) $validated['learner_id'] !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to modify another learner\'s skills.',
            ], 403);
        }

        $learnerSkill = LearnerSkill::updateOrCreate(
            [
                'learner_id' => $validated['learner_id'],
                'skill_id' => $validated['skill_id'],
            ],
            [
                'level' => $validated['level'],
                'confidence_score' => $validated['confidence_score'],
                'source_type' => $validated['source_type'] ?? null,
                'algorithm_version' => $validated['algorithm_version'] ?? null,
                'configuration_version' => $validated['configuration_version'] ?? null,
                'source_contributions' => $validated['source_contributions'] ?? null,
                'calculated_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Skill assigned/updated successfully.',
            'data' => $learnerSkill,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $query = LearnerSkill::query();

        if (! $request->user()->hasRole('admin')) {
            $query->where('learner_id', $request->user()->id);
        }

        $learnerSkill = $query->findOrFail($id);

        $validated = $request->validate([
            'level' => 'sometimes|numeric|between:0.00,5.00',
            'confidence_score' => 'sometimes|numeric|between:0.00,100.00',
            'source_type' => 'sometimes|string',
            'algorithm_version' => 'sometimes|string',
            'configuration_version' => 'sometimes|string',
            'source_contributions' => 'sometimes|array',
        ]);

        $learnerSkill->update([
            ...$validated,
            'calculated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Skill updated successfully.',
            'data' => $learnerSkill,
        ]);
    }

    public function removeFromLearner(Request $request, string $skill)
    {
        $skillId = (int) $skill;

        $deleted = LearnerSkill::query()
            ->where('learner_id', $request->user()->id)
            ->where('skill_id', $skillId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Skill is not associated with this learner.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skill removed successfully.',
            'data' => null,
        ]);
    }
}
