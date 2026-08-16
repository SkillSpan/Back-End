<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SetupController extends Controller
{
    /**
     * POST /api/setup/create-admin
     *
     * رابط خاص دائم لإنشاء حسابات أدمن، محمي بسر (ADMIN_SETUP_SECRET)
     * لازم يكون محطوط بمتغيرات البيئة. خليه عندك إنت بس، وشاركه بس
     * مع أعضاء الفريق يلي فعلًا محتاجين يعملوا حساب أدمن.
     */
    public function createAdmin(Request $request): JsonResponse
    {
        $configuredSecret = (string) env('ADMIN_SETUP_SECRET', '');

        if ($configuredSecret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Admin setup is disabled. Set ADMIN_SETUP_SECRET in the environment first.',
            ], 403);
        }

        if (! hash_equals($configuredSecret, (string) $request->input('secret'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid setup secret.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'Platform administrator']
        );

        $admin->roles()->attach($role->id, ['organization_id' => null]);

        $token = $admin->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Admin account created successfully. Save this token now, or log in normally afterwards via /api/auth/login.',
            'data' => [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }
}
