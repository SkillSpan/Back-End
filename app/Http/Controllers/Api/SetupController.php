<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Traits\AuditsActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SetupController extends Controller
{
    use AuditsActions;

    public function __construct(protected AuthService $authService) {}

    public function createAdmin(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnlessAuthorised($request, 'Admin', 'admin_setup', 'ADMIN_SETUP_SECRET')) {
            return $refusal;
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

        $this->audit(
            actorId: null,
            action: 'setup.admin_created',
            entityType: User::class,
            entityId: $admin->id,
            after: [
                'email' => $admin->email,
                'role' => 'admin',
                'status' => $admin->status,
            ],
            purpose: 'shared_secret_bootstrap',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

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

    public function createMentor(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnlessAuthorised($request, 'Mentor', 'mentor_setup', 'MENTOR_SETUP_SECRET')) {
            return $refusal;
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'expertise' => ['nullable', 'string', 'max:2000'],
            'affiliation' => ['nullable', 'string', 'max:255'],
            'availability' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = User::withTrashed()->where('email', $data['email'])->first();

        if ($user && ($user->trashed() || in_array($user->status, ['suspended', 'deleted'], true))) {
            $reason = $user->trashed() ? 'deleted' : $user->status;

            return response()->json([
                'success' => false,
                'message' => "An account with this email already exists and its status is {$reason}. Restore or reactivate it before promoting it to mentor.",
            ], 422);
        }

        if ($user && $user->hasRole('admin')) {
            Log::warning('Mentor setup refused: the target account is a platform administrator.', [
                'ip' => $request->ip(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An account with this email is a platform administrator. The mentor setup secret cannot modify administrator accounts.',
            ], 422);
        }

        $registered = false;
        $passwordReset = false;
        $generatedPassword = null;

        if (! $user) {
            if (! empty($data['password'])) {
                $plainPassword = $data['password'];
            } else {
                $plainPassword = Str::random(16);
                $generatedPassword = $plainPassword;
            }

            $user = User::forceCreate([
                'name' => $data['name'] ?? Str::before($data['email'], '@'),
                'email' => $data['email'],
                'password' => Hash::make($plainPassword),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $registered = true;
        } else {
            $updates = [];

            if ($user->status === 'pending') {
                $updates['status'] = 'active';
                $updates['email_verified_at'] = $user->email_verified_at ?? now();
            }

            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
                $passwordReset = true;
            }

            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }
        }

        $profile = ProfessionalProfile::firstOrNew(['user_id' => $user->id]);

        $profile->fill([
            'type' => 'mentor',
            'expertise' => $data['expertise'] ?? null,
            'affiliation' => $data['affiliation'] ?? null,
            'availability' => $data['availability'] ?? null,
        ]);

        $profile->verification_status = 'verified';
        $profile->save();

        $this->audit(
            actorId: null,
            action: 'setup.mentor_provisioned',
            entityType: User::class,
            entityId: $user->id,
            after: [
                'email' => $user->email,
                'registered' => $registered,
                'password_reset' => $passwordReset,
                'verification_status' => $profile->verification_status,
            ],
            purpose: 'shared_secret_bootstrap',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => $registered
                ? 'Mentor account created and verified. This user can log in and access the mentor endpoints straight away.'
                : 'Mentor profile created successfully. This user can now access the mentor endpoints.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'registered' => $registered,
                'password_reset' => $passwordReset,
                'status' => $user->status,
                'generated_password' => $generatedPassword,
                'professional_profile_id' => $profile->id,
                'type' => $profile->type,
                'verification_status' => $profile->verification_status,
            ],
        ], 201);
    }

    private function refuseUnlessAuthorised(
        Request $request,
        string $label,
        string $configKey,
        string $envName,
    ): ?JsonResponse {
        if (! (bool) config("services.{$configKey}.enabled", true)) {
            Log::warning("{$label} setup rejected: disabled by configuration.", [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "{$label} setup is disabled.",
            ], 403);
        }

        $configuredSecret = (string) config("services.{$configKey}.secret", '');

        if ($configuredSecret === '') {
            return response()->json([
                'success' => false,
                'message' => "{$label} setup is disabled. Set {$envName} in the environment first.",
            ], 403);
        }

        if (! hash_equals($configuredSecret, (string) $request->input('secret'))) {
            Log::warning("{$label} setup rejected: invalid secret.", [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid setup secret.',
            ], 403);
        }

        return null;
    }
}
