<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareerRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavedCareerRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = CareerRole::query()
            ->where('status', 'approved')
            ->whereIn('id', DB::table('saved_career_roles')
                ->where('user_id', $request->user()->id)->select('career_role_id'))
            ->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        // Saving a bookmark never changes the learner's primary career goal.
        $role = CareerRole::query()->whereKey($id)
            ->where('status', 'approved')->where('is_active', true)->firstOrFail();

        DB::table('saved_career_roles')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'career_role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => [
            'career_role_id' => $role->id, 'is_saved' => true,
        ]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // Removal stays possible even if a bookmarked role was deactivated.
        DB::table('saved_career_roles')->where('user_id', $request->user()->id)
            ->where('career_role_id', $id)->delete();

        return response()->json(['success' => true, 'data' => [
            'career_role_id' => $id, 'is_saved' => false,
        ]]);
    }
}
