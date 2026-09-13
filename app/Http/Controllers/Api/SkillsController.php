<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\Request;

class SkillsController extends Controller
{
    /**
     * GET /api/v1/skills/taxonomy
     * Retrieve all skills with their taxonomy information.
     */
    public function taxonomy()
    {
        $skills = Skill::with(['aliases', 'parent', 'children', 'careerRoles'])
            ->where('status', 'active')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Skills taxonomy retrieved successfully.',
            'data' => $skills,
        ]);
    }

    /**
     * GET /api/v1/skills/matrix
     * Retrieve the skill matrix for a learner.
     *
     * FIX: this previously ignored the current user entirely — the route has
     * no {learnerId} segment, so the old $learnerId/$careerRoleId method
     * arguments were never populated and every call returned the full
     * learner_skills table for every learner. Non-admins now always get
     * their own matrix; only admins may query another learner's matrix via
     * ?learner_id=.
     */
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

    /**
     * POST /api/v1/skills/matrix
     * Assign or update skills for a learner.
     */
    public function store(Request $request)
    {
        $request->validate([
            'learner_id' => 'required|exists:users,id',
            'skill_id' => 'required|exists:skills,id',
            'level' => 'required|numeric|between:0.00,5.00',
            'confidence_score' => 'required|numeric|between:0.00,100.00',
            'source_type' => 'sometimes|required|string',
            'algorithm_version' => 'sometimes|required|string',
            'configuration_version' => 'sometimes|required|string',
            'source_contributions' => 'sometimes|required|json',
        ]);

        $learnerSkill = LearnerSkill::updateOrCreate(
            ['learner_id' => $request->learner_id, 'skill_id' => $request->skill_id],
            [
                'level' => $request->level,
                'confidence_score' => $request->confidence_score,
                'source_type' => $request->source_type,
                'algorithm_version' => $request->algorithm_version,
                'configuration_version' => $request->configuration_version,
                'source_contributions' => $request->source_contributions,
                'calculated_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Skill assigned/updated successfully.',
            'data' => $learnerSkill,
        ], 201);
    }

    /**
     * PUT /api/v1/skills/matrix/{id}
     * Update a specific learner skill record.
     */
    public function update(Request $request, $id)
    {
        $learnerSkill = LearnerSkill::findOrFail($id);

        $request->validate([
            'level' => 'sometimes|numeric|between:0.00,5.00',
            'confidence_score' => 'sometimes|numeric|between:0.00,100.00',
            'source_type' => 'sometimes|string',
            'algorithm_version' => 'sometimes|string',
            'configuration_version' => 'sometimes|string',
            'source_contributions' => 'sometimes|json',
        ]);

        $learnerSkill->update($request->only([
            'level',
            'confidence_score',
            'source_type',
            'algorithm_version',
            'configuration_version',
            'source_contributions',
            'calculated_at',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Skill updated successfully.',
            'data' => $learnerSkill,
        ]);
    }
}
