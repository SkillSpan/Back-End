<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

    /**
     * POST /api/v1/setup/create-mentor
     *
     * رابط خاص محمي بسر (MENTOR_SETUP_SECRET) لازم يكون محطوط بمتغيرات
     * البيئة. بيسجّل المرشد وخلاص — ما فيش فلو OTP/verify هون: السر نفسه
     * هو التصريح، وإنت المسؤول الوحيد عن تسجيل المرشدين.
     *
     * - الإيميل مش موجود → بينشئ حساب جديد status=active ومفعّل على طول.
     * - الإيميل موجود وحالته pending → بيفعّله (صاحب السر بيمثّل الضمانة).
     * - الإيميل موجود وحالته active → بيرقّيه مرشد.
     * - suspended / deleted / soft-deleted → 422.
     *
     * وبعدين بيعمل/بيحدّث ProfessionalProfile بـ type=mentor و
     * verification_status=verified — وده اللي بيفتحله كل مسارات المرشد.
     *
     * ولو بعتت `password` على إيميل موجود، الباسورد بيتبدّل. يعني نفس الرابط
     * ده هو مسار الـ reset بتاعك لو المرشد نسي باسوره — مش محتاج OTP ولا
     * وصول على إيميله. لو سِبت `password` فاضي، الباسورد القديم ما بيتغيّرش.
     */
    public function createMentor(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('services.mentor_setup.secret', '');

        if ($configuredSecret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Mentor setup is disabled. Set MENTOR_SETUP_SECRET in the environment first.',
            ], 403);
        }

        if (! hash_equals($configuredSecret, (string) $request->input('secret'))) {
            Log::warning('Mentor setup rejected: invalid secret.', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid setup secret.',
            ], 403);
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

        // No exists:users,email rule any more — a missing account gets created
        // below, that is the whole point of this endpoint. withTrashed() is
        // essential: a soft-deleted row still occupies the unique index on
        // users.email, so skipping it here would send forceCreate() straight
        // into a UNIQUE constraint violation (a 500) instead of a clean 422.
        $user = User::withTrashed()->where('email', $data['email'])->first();

        if ($user && ($user->trashed() || in_array($user->status, ['suspended', 'deleted'], true))) {
            $reason = $user->trashed() ? 'deleted' : $user->status;

            return response()->json([
                'success' => false,
                'message' => "An account with this email already exists and its status is {$reason}. Restore or reactivate it before promoting it to mentor.",
            ], 422);
        }

        $registered = false;
        $passwordReset = false;
        $generatedPassword = null;

        if (! $user) {
            if (! empty($data['password'])) {
                $plainPassword = $data['password'];
            } else {
                // Nothing was supplied, so mint one. It is echoed back exactly
                // once, below, so the admin can hand it to the mentor — it is
                // never recoverable afterwards.
                $plainPassword = Str::random(16);
                $generatedPassword = $plainPassword;
            }

            // email_verified_at is stamped directly: there is no OTP round-trip
            // on this flow, and /auth/login rejects any account without it.
            // The shared secret is the authorisation instead.
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
                // Self-registered but never finished the OTP step. The secret
                // holder is vouching for them, so activate instead of dead-ending.
                $updates['status'] = 'active';
                $updates['email_verified_at'] = $user->email_verified_at ?? now();
            }

            // A supplied password always wins, which is what makes re-calling
            // this endpoint the admin's recovery path for a mentor who forgot
            // theirs. Omitting it deliberately leaves the old password alone
            // rather than silently minting a new one.
            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
                $passwordReset = true;
            }

            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }
        }

        // user_id is unique on professional_profiles, so firstOrNew promotes an
        // existing profile instead of colliding on a duplicate row.
        $profile = ProfessionalProfile::firstOrNew(['user_id' => $user->id]);

        $profile->fill([
            'type' => 'mentor',
            'expertise' => $data['expertise'] ?? null,
            'affiliation' => $data['affiliation'] ?? null,
            'availability' => $data['availability'] ?? null,
        ]);

        // verification_status is deliberately NOT mass-assignable on
        // ProfessionalProfile: EnsureUserIsMentor gates every mentor endpoint on
        // it, so letting it ride in through fill() would turn any future
        // create($request->all()) into a self-promotion to verified mentor.
        // It is set explicitly here instead — promotion is the whole point of
        // this endpoint, so it must never be silently dropped.
        $profile->verification_status = 'verified';
        $profile->save();

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
                // Only ever populated when we minted the password ourselves.
                'generated_password' => $generatedPassword,
                'professional_profile_id' => $profile->id,
                'type' => $profile->type,
                'verification_status' => $profile->verification_status,
            ],
        ], 201);
    }
}
