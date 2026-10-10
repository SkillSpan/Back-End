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

/**
 * The panel user's own profile — the one screen every panel account owns.
 *
 * WHY IT IS NOT ADMIN-ONLY
 * ------------------------
 * Mentors open the panel too (see {@see PanelAccess}), and they need a name,
 * an avatar and a password they can change exactly as much as an
 * administrator does. Gating this on `admin` would leave every mentor stuck
 * with whatever the seeder gave them.
 *
 * WHAT THE CALLER MAY TOUCH
 * -------------------------
 * Only their own row. There is no id in any route or payload: every method
 * reads `$request->user()` and passes that to the service, so there is no
 * request shape that edits somebody else's profile. The service holds the
 * same rule from the other side, so neither layer can be the single point of
 * failure.
 *
 * `name` is the exception that proves it: it lives on `users`, which is shared
 * identity rather than presentation. Changing it therefore changes the name
 * every other screen shows. That is intended — it is the person's own name —
 * but it is why the write goes through the service's transaction rather than a
 * bare `update()` here.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It never writes a role, and it never reads one to decide what may be edited.
 * Authorisation is `user_role` and `ProfessionalProfile`; nothing in this
 * controller can widen what an account can reach.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AdminProfileService $profileService) {}

    /**
     * GET /admin/api/profile
     *
     * Everything the profile screen renders, in one round trip: the identity
     * fields, the panel role, the account timestamps, the presentation fields
     * and the viewer's own support counters.
     */
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

    /**
     * PATCH /admin/api/profile
     *
     * Name, display title, bio and age. Only the keys actually sent are
     * touched, so the UI can save one field without sending the rest.
     */
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

    /**
     * POST /admin/api/profile/avatar
     *
     * Multipart upload. The image is decoded, centre-cropped and re-encoded
     * before it is stored — see AdminProfileService for why the bytes are
     * processed rather than kept as uploaded.
     */
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

    /**
     * DELETE /admin/api/profile/avatar
     *
     * Back to initials. Idempotent: removing an absent avatar is a success,
     * because the caller's intent ("I want no photo") is already satisfied.
     */
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

    /**
     * POST /admin/api/profile/password
     *
     * The current password is required even though the caller already holds an
     * authenticated session — see AdminProfileService::changePassword().
     */
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

    /**
     * GET /admin/profile/avatar
     *
     * The image itself, straight from the row.
     *
     * Served from its own route rather than inlined into the JSON for one
     * reason: a stable URL is cacheable by the browser, whereas a base64 field
     * inside a payload is re-sent on every profile read and defeats caching
     * entirely. The `?v=` query string the caller is handed changes whenever
     * the bytes do, so the long `max-age` below can never serve a stale photo.
     *
     * A caller with no avatar gets a 404 rather than a placeholder image: the
     * page renders initials instead, so "no photo" is a miss, not a picture of
     * a grey person.
     */
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
            // The stored mime is the one *we* produced during re-encoding, not
            // the one the client claimed, so it is safe to echo back.
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            // Belt and braces: even a correctly-typed image must not be sniffed
            // into something executable.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Payload ───────────────────────────────────────────────────────────

    /**
     * The single shape every write method returns.
     *
     * Returning the full payload after a write (rather than a bare success)
     * is what lets the page re-render from the server's values instead of
     * guessing what the stored result was — which matters for the derived
     * `display_name` and for the avatar URL's version token.
     *
     * @return array<string, mixed>
     */
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
                // Derived here, not in the browser, so the profile header and
                // the sidebar can never show two different names.
                'display_name' => $profile->display_title ?: $this->roleLabel($user),
                'bio' => $profile->bio,
                'age' => $profile->age,
                'has_avatar' => $profile->hasAvatar(),
                'avatar_url' => $this->avatarUrl($profile),
                'avatar_updated_at' => $profile->avatar_updated_at?->toIso8601String(),
                // Lets the panel render its character counters without
                // hard-coding numbers that would drift from the validation.
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

    /**
     * The viewer's support counters, scoped exactly like the inbox.
     *
     * An administrator sees the whole queue; a mentor sees only their own
     * assignments. Showing a mentor platform-wide totals on their profile
     * would contradict the inbox one click away, and the number would be
     * meaningless to them anyway.
     *
     * @return array<string, int|string>
     */
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

    /**
     * The role a person would name if asked, for display only.
     *
     * Delegated to {@see PanelAccess} because the sidebar renders the same
     * label; two copies of the rule would eventually disagree.
     */
    private function roleLabel(User $user): string
    {
        return PanelAccess::roleLabel($user);
    }

    /**
     * Browser-facing URL for the avatar, or null when there is none.
     *
     * The version token comes from `avatar_updated_at`, so uploading a new
     * photo produces a new URL and the browser fetches it immediately despite
     * the year-long cache on the response.
     */
    private function avatarUrl(AdminProfile $profile): ?string
    {
        $version = $profile->avatarVersion();

        if ($version === null) {
            return null;
        }

        return route('admin.profile.avatar', ['v' => $version]);
    }

    // ── Guards & envelope ─────────────────────────────────────────────────

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
