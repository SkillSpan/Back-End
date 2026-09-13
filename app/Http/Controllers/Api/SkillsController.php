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
        $validated = $request->validate([
            'learner_id' => 'required|exists:users,id',
            'skill_id' => 'required|exists:skills,id',
            'level' => 'required|numeric|between:0.00,5.00',
            'confidence_score' => 'required|numeric|between:0.00,100.00',
            'source_type' => 'sometimes|required|string',
            'algorithm_version' => 'sometimes|required|string',
            'configuration_version' => 'sometimes|required|string',
            // A structured payload, NOT a JSON string. The model casts this
            // column to `array`, and Eloquent encodes on write — so handing
            // it an already-encoded string encoded it a second time and the
            // stored value read back as a string instead of an array.
            'source_contributions' => 'sometimes|array',
        ]);

        $user = $request->user();

        // AUTHZ: a learner may only ever write their own skill rows. Without
        // this check any authenticated user could POST an arbitrary
        // learner_id and create or overwrite another learner's level,
        // confidence score and provenance metadata. matrix() already scoped
        // its read the same way; the write path was left open.
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

    /**
     * PUT /api/v1/skills/matrix/{id}
     * Update a specific learner skill record.
     */
    public function update(Request $request, $id)
    {
        // AUTHZ: scope the lookup to the caller first, so a non-admin asking
        // for another learner's row gets a 404 (the row is simply not visible
        // to them) instead of a 403 that would confirm the id exists.
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

        // Only validated fields are written. `calculated_at` is deliberately
        // NOT accepted from the client — it is a server-managed provenance
        // timestamp. The previous version read it with $request->only(),
        // which pulls from the raw input bag rather than validated data, so
        // an unvalidated client value reached the model and let a caller
        // forge when their scores were computed.
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
}
