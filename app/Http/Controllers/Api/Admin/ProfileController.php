<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateAdminProfileRequest;
use App\Http\Requests\UpdateAvatarRequest;
use App\Models\AdminProfile;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Profile\AdminProfileService;
use App\Support\PanelAccess;
use App\Support\SafeLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(private readonly AdminProfileService $profileService) {}

    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $user = $request->user();
        $profile = $this->profileService->profileFor($user);

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $this->payload($request, $user, $profile),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function updateDetails(UpdateAdminProfileRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $user = $request->user();
            $profile = $this->profileService->updateDetails($user, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data' => $this->payload($request, $user, $profile),
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function uploadAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $user = $request->user();
            $profile = $this->profileService->storeAvatar($user, $request->file('avatar'));

            return response()->json([
                'success' => true,
                'message' => 'Profile photo updated successfully.',
                'data' => $this->payload($request, $user, $profile),
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $user = $request->user();
            $profile = $this->profileService->removeAvatar($user);

            return response()->json([
                'success' => true,
                'message' => 'Profile photo removed successfully.',
                'data' => $this->payload($request, $user, $profile),
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $this->profileService->changePassword(
                $request->user(),
                (string) $request->validated('current_password'),
                (string) $request->validated('password'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully.',
                'data' => ['changed_at' => now()->toIso8601String()],
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function avatar(Request $request): Response
    {
        if (! PanelAccess::allows($request->user())) {
            abort(403);
        }

        $profile = $this->profileService->profileFor($request->user());
        $binary = $this->profileService->avatarBinary($profile);

        if ($binary === null) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function payload(Request $request, User $user, AdminProfile $profile): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'role' => $this->roleLabel($user),
            'role_slugs' => $user->roles()->pluck('slug')->all(),
            'profile' => [
                'display_title' => $profile->display_title,
                'display_name' => $profile->display_title ?: $this->roleLabel($user),
                'bio' => $profile->bio,
                'age' => $profile->age,
                'has_avatar' => $profile->hasAvatar(),
                'avatar_url' => $this->avatarUrl($profile),
                'avatar_updated_at' => $profile->avatar_updated_at?->toIso8601String(),
                'limits' => [
                    'display_title' => 60,
                    'bio' => 600,
                    'age_min' => 16,
                    'age_max' => 100,
                    'avatar_max_kb' => (int) config('services.profile.avatar_max_upload_kb', 4096),
                ],
            ],
            'stats' => $this->supportStats($user),
        ];
    }

    private function supportStats(User $user): array
    {
        $isAdmin = PanelAccess::isAdministrator($user);

        $scoped = fn (): Builder => $isAdmin
            ? SupportRequest::query()
            : SupportRequest::query()->where('assigned_to', $user->id);

        return [
            'scope' => $isAdmin ? 'all' : 'assigned',
            'total' => $scoped()->count(),
            'pending' => $scoped()->where('status', SupportRequest::STATUS_PENDING)->count(),
            'assigned' => $scoped()->where('status', SupportRequest::STATUS_ASSIGNED)->count(),
            'resolved' => $scoped()->where('status', SupportRequest::STATUS_RESOLVED)->count(),
        ];
    }

    private function roleLabel(User $user): string
    {
        return PanelAccess::roleLabel($user);
    }

    private function avatarUrl(AdminProfile $profile): ?string
    {
        $version = $profile->avatarVersion();

        if ($version === null) {
            return null;
        }

        return route('admin.profile.avatar', ['v' => $version]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (PanelAccess::allows($request->user())) {
            return null;
        }

        return $this->errorResponse(
            'PROFILE_FORBIDDEN',
            'You do not have access to this profile.',
            403,
            $this->requestId($request),
        );
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Admin profile request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->errorResponse(
            'PROFILE_REQUEST_FAILED',
            'The profile request could not be completed.',
            500,
            $requestId,
        );
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}
