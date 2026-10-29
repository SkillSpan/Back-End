<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Models\User;
use App\Support\ReferenceCache;
use App\Support\ReferenceResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SkillsController extends Controller
{
    /**
     * GET /api/v1/skills/taxonomy
     * Retrieve all skills with their taxonomy information.
     */
    public function taxonomy(Request $request)
    {
        // Pure reference data: no learner context, no user argument, and the
        // application never writes skills at runtime (they are seeded). Eager
        // loading four relations costs five round trips against a database
        // hosted on a different provider, so the result is cached as plain
        // arrays — the JSON is identical to what this endpoint already returned.
        $skills = Cache::remember(
            ReferenceCache::SKILLS_TAXONOMY,
            ReferenceCache::TTL_SECONDS,
            fn (): array => Skill::with(['aliases', 'parent', 'children', 'careerRoles'])
                ->where('status', 'active')
                ->get()
                ->toArray(),
        );

        // `$shared: false` — the payload is identical for every learner, but the
        // route sits behind auth:sanctum, so it must not be marked public.
        return ReferenceResponse::make(
            'Skills taxonomy retrieved successfully.',
            $skills,
            $request,
            shared: false,
        );
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

    /**
     * DELETE /api/v1/learner/skills/{skill}
     *
     * Remove ONE skill from the authenticated learner's own skill list.
     *
     * This deletes the `learner_skills` relation and nothing else. The row in
     * `skills` is shared reference data — career_role_skills, project
     * requirements, baseline snapshots and every other learner point at it —
     * so it is never touched here. That is also why the route is NOT
     * `DELETE /api/v1/skills/{id}`, which would read as "delete the skill
     * from the system".
     *
     * AUTHZ: the learner id is taken from the authenticated request and from
     * nowhere else. No learner_id is read from the body or the query string,
     * and because the learner id is part of the DELETE's own WHERE clause, a
     * skill that belongs to another learner simply matches zero rows — there
     * is no row to look up first, so there is no row to get wrong.
     */
    public function removeFromLearner(Request $request, string $skill)
    {
        // Route params arrive as strings; the route's whereNumber() constraint
        // guarantees digits, so the cast cannot fail. Casting (rather than
        // type-hinting int) also keeps an absurdly long id from raising a
        // TypeError — it simply matches no rows and falls through to the 404.
        $skillId = (int) $skill;

        $deleted = LearnerSkill::query()
            ->where('learner_id', $request->user()->id)
            ->where('skill_id', $skillId)
            ->delete();

        if ($deleted === 0) {
            // Deliberately the same answer for "this skill is not yours" and
            // "this skill id does not exist": the two cases must be
            // indistinguishable, or the endpoint becomes a probe for the
            // taxonomy and for other learners' skill lists.
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
