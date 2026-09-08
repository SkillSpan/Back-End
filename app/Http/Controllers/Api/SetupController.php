<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SetupController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    /**
     * POST /api/v1/setup/create-admin
     *
     * رابط خاص دائم لإنشاء حسابات أدمن، محمي بسر (ADMIN_SETUP_SECRET)
     * لازم يكون محطوط بمتغيرات البيئة. خليه عندك إنت بس، وشاركه بس
     * مع أعضاء الفريق يلي فعلًا محتاجين يعملوا حساب أدمن.
     */
    public function createAdmin(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('services.admin_setup.secret', '');

        if ($configuredSecret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Admin setup is disabled. Set ADMIN_SETUP_SECRET in the environment first.',
            ], 403);
        }

        if (! hash_equals($configuredSecret, (string) $request->input('secret'))) {
            Log::warning('Admin setup rejected: invalid secret.', [
                'ip' => $request->ip(),
            ]);

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

        $admin = User::forceCreate([
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

        $tokenResult = $admin->createToken('auth_token');
        $this->authService->recordAuthSession($admin, $tokenResult, $request);

        return response()->json([
            'success' => true,
            'message' => 'Admin account created successfully. Save this token now, or log in normally afterwards via /api/v1/auth/login.',
            'data' => [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'token' => $tokenResult->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }
}
