<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CareerRole;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerRoleController extends Controller
{
    /**
     * GET /api/v1/career-roles
     * List all approved career roles for a learner.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Learners can only see approved career roles
        $careerRoles = CareerRole::where('status', 'approved')
            ->withCount('skills as skills_count')
            ->latest('version')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $careerRoles,
        ]);
    }

    /**
     * GET /api/v1/career-roles/{id}
     * Retrieve a specific career role with its skills and details.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $careerRole = CareerRole::with('skills')
            ->where('status', 'approved')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Career role retrieved successfully.',
            'data' => $careerRole,
        ]);
    }

    /**
     * GET /api/v1/career-roles/{id}/skills
     * Retrieve required skills for a career role with importance weights and critical flags.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function skills($id)
    {
        $careerRole = CareerRole::where('status', 'approved')
            ->with(['skills' => function ($query) {
                $query->select('skill_id', 'required_level', 'importance_weight', 'is_critical');
            }])
            ->findOrFail($id);

        // Format the skills data for frontend consumption
        $formattedSkills = $careerRole->skills->map(function ($skill) {
            return [
                'skill_id' => $skill->id,
                'skill_name' => $skill->name,
                'slug' => $skill->slug,
                'required_level' => $skill->pivot->required_level,
                'importance_weight' => $skill->pivot->importance_weight,
                'is_critical' => $skill->pivot->is_critical,
                'prerequisite_skill_id' => $skill->prerequisite_skill_id,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Career role skills retrieved successfully.',
            'data' => [
                'career_role_id' => $id,
                'career_role_title' => $careerRole->title,
                'skills' => $formattedSkills,
                'statistics' => [
                    'total_skills' => $formattedSkills->count(),
                    'critical_skills_count' => $formattedSkills->where('is_critical', true)->count(),
                    'average_importance_weight' => $formattedSkills->isNotEmpty()
                        ? round(array_sum($formattedSkills->pluck('importance_weight')->toArray()) / count($formattedSkills->pluck('importance_weight')->toArray()), 3)
                        : 0,
                ],
            ],
        ]);
    }
}