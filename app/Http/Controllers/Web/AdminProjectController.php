<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\ProjectController as ApiProjectController;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Session-authenticated twin of the admin projects API.
 *
 * Project CRUD and lifecycle behavior stay inherited from the canonical
 * Admin API controller. The reference endpoints below exist only so the
 * browser session can populate the Career Role -> Skills chain without a
 * Sanctum bearer token.
 */
class AdminProjectController extends ApiProjectController
{
    public function careerRoles(Request $request): JsonResponse
    {
        $requestId = $this->webRequestId($request);

        $roles = CareerRole::query()
            ->where('status', 'approved')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'version'])
            ->map(fn (CareerRole $role) => [
                'id' => (int) $role->id,
                'title' => (string) $role->title,
                'slug' => (string) $role->slug,
                'version' => (int) $role->version,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $roles,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function careerRoleSkills(Request $request, int $careerRole): JsonResponse
    {
        $requestId = $this->webRequestId($request);

        $role = CareerRole::query()
            ->whereKey($careerRole)
            ->where('status', 'approved')
            ->first();

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => 'The selected career role does not exist or is not approved.',
                'request_id' => $requestId,
            ], 404, ['X-Request-ID' => $requestId]);
        }

        $skills = CareerRoleSkill::query()
            ->where('career_role_id', $careerRole)
            ->with('skill:id,name,slug')
            ->get()
            ->filter(fn (CareerRoleSkill $row) => $row->skill !== null)
            ->map(fn (CareerRoleSkill $row) => [
                'id' => (int) $row->skill->id,
                'name' => (string) $row->skill->name,
                'slug' => (string) $row->skill->slug,
            ])
            ->sortBy('name')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Career role skills retrieved successfully.',
            'data' => $skills,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function webRequestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }
}
