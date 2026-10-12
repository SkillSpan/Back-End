<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\UploadedFile;
use App\Notifications\OrganizationApprovedNotification;
use App\Notifications\OrganizationRejectedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Admin review/approval workflow for organization (company / university /
 * training partner) registrations. Per SRS PROF-02, ADM-01 and ADM-03, a
 * newly registered organization stays in "pending" state until an
 * authorized administrator reviews the uploaded proof document and
 * approves or rejects the registration.
 */
class OrganizationController extends Controller
{
    /**
     * GET /api/admin/organizations
     * List organization registration requests, optionally filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = Organization::query()->with('verifier')->latest();

        if ($status && in_array($status, ['pending', 'verified', 'rejected'], true)) {
            $query->where('verification_status', $status);
        }

        $organizations = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully.',
            'data' => $organizations,
        ]);
    }

    /**
     * GET /api/admin/organizations/{organization}
     * Show a single organization request with a link to its uploaded proof
     * document so the admin can review it before deciding.
     */
    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully.',
            'data' => $this->transform($organization),
        ]);
    }

    /**
     * POST /api/admin/organizations/{organization}/approve
     * Approve the organization: mark it verified, mark the proof document
     * as approved, and notify the organization admin by email.
     */
    public function approve(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->verification_status !== 'pending') {
            return $this->alreadyReviewedResponse($organization);
        }

        // ADM: never approve an organization that has no submitted proof
        // document — approving "verified" without evidence would be a
        // false trust signal.
        if (! $organization->proofFile()) {
            return response()->json([
                'success' => false,
                'message' => 'This organization has no proof document to review. It cannot be approved.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $before = $organization->only(['verification_status', 'verified_at', 'verified_by']);

            if (! $this->claimPendingOrganization($organization, $request->user()->id, 'verified')) {
                DB::rollBack();

                return $this->alreadyReviewedResponse($organization->refresh());
            }

            $proofFile = $organization->proofFile();
            $proofFile?->forceFill(['status' => 'approved'])->save();

            AuditEvent::forceCreate([
                'actor_id' => $request->user()->id,
                'action' => 'organization.approved',
                'entity_type' => Organization::class,
                'entity_id' => $organization->id,
                'before' => $before,
                'after' => $organization->only(['verification_status', 'verified_at', 'verified_by']),
                'purpose' => 'Organization registration review',
                'occurred_at' => now(),
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->notifyOrganizationAdmins($organization, new OrganizationApprovedNotification($organization));

        return response()->json([
            'success' => true,
            'message' => 'The organization has been approved successfully.',
            'data' => $this->transform($organization->fresh(['verifier'])),
        ]);
    }

    /**
     * POST /api/admin/organizations/{organization}/reject
     * Reject the organization with an optional reason and notify the admin.
     */
    public function reject(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->verification_status !== 'pending') {
            return $this->alreadyReviewedResponse($organization);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The provided data is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reason = $validator->validated()['reason'] ?? null;

        try {
            DB::beginTransaction();

            $before = $organization->only(['verification_status', 'verified_at', 'verified_by']);

            if (! $this->claimPendingOrganization($organization, $request->user()->id, 'rejected')) {
                DB::rollBack();

                return $this->alreadyReviewedResponse($organization->refresh());
            }

            $proofFile = $organization->proofFile();
            $proofFile?->forceFill(['status' => 'rejected'])->save();

            AuditEvent::forceCreate([
                'actor_id' => $request->user()->id,
                'action' => 'organization.rejected',
                'entity_type' => Organization::class,
                'entity_id' => $organization->id,
                'before' => $before,
                'after' => array_merge(
                    $organization->only(['verification_status', 'verified_at', 'verified_by']),
                    ['reason' => $reason]
                ),
                'purpose' => 'Organization registration review',
                'occurred_at' => now(),
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->notifyOrganizationAdmins($organization, new OrganizationRejectedNotification($organization, $reason));

        return response()->json([
            'success' => true,
            'message' => 'The organization has been rejected.',
            'data' => $this->transform($organization->fresh(['verifier'])),
        ]);
    }

    /**
     * Move the organization out of `pending` and return whether THIS call was
     * the one that did it.
     *
     * Both review actions check `verification_status !== 'pending'` before
     * they start, but that is a read followed by a write: two requests that
     * arrive together — a double-click on the panel, or a client retry after a
     * timeout — both read `pending` and both carry on to send the email.
     * Restating the condition inside the UPDATE makes the transition atomic,
     * so exactly one request can ever claim the row and exactly one email is
     * sent. The loser is answered the same way as a sequential repeat.
     */
    private function claimPendingOrganization(Organization $organization, int $actorId, string $status): bool
    {
        $claimed = Organization::whereKey($organization->getKey())
            ->where('verification_status', 'pending')
            ->update([
                'verification_status' => $status,
                // Only an approval carries a verification timestamp.
                'verified_at' => $status === 'verified' ? now() : null,
                'verified_by' => $actorId,
            ]);

        if ($claimed === 0) {
            return false;
        }

        // The UPDATE above went straight to the database, so the in-memory
        // model still holds the old attributes; the audit snapshot and the
        // response both read from it.
        $organization->refresh();

        return true;
    }

    /**
     * The one response for "this organization is not pending any more".
     * Shared by the pre-check and the atomic guard so that a repeat and a
     * race are indistinguishable to the caller.
     */
    private function alreadyReviewedResponse(Organization $organization): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This organization has already been reviewed.',
            'data' => [
                'verification_status' => $organization->verification_status,
            ],
        ], 422);
    }

    private function transform(Organization $organization): array
    {
        $proofFile = $organization->proofFile();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'type' => $organization->type,
            'verification_status' => $organization->verification_status,
            'verified_at' => $organization->verified_at?->toIso8601String(),
            'verified_by' => $organization->verifier?->only(['id', 'name', 'email']),
            'contact_email' => $organization->contact_email,
            'contact_phone' => $organization->contact_phone,
            'website' => $organization->website,
            // The review panel renders this in the expanded card. Without it
            // the panel cannot tell "no description was submitted" apart from
            // "the API did not send one", and always claimed the former.
            'description' => $organization->description,
            'industry' => $organization->industry,
            'company_size' => $organization->company_size,
            'country' => $organization->country,
            'city' => $organization->city,
            'address' => $organization->address,
            'postal_code' => $organization->postal_code,
            'created_at' => $organization->created_at?->toIso8601String(),
            'proof_file' => $proofFile ? $this->transformProofFile($proofFile) : null,
        ];
    }

    public function downloadProofFile(Organization $organization)
    {
        $file = $organization->proofFile();

        // The default disk, matching wherever AuthService::uploadProofFile
        // stored it — see the note there about container filesystems.
        if (! $file || ! Storage::exists($file->path)) {
            return response()->json([
                'success' => false,
                'message' => 'Proof file not found.',
            ], 404);
        }

        return Storage::response($file->path, null, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    private function transformProofFile(UploadedFile $file): array
    {
        return [
            'id' => $file->id,
            'status' => $file->status,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'uploaded_at' => $file->created_at?->toIso8601String(),
            'download_url' => route($this->proofFileRouteName(), $file->fileable_id),
            // Whether the file is actually on the configured disk. The row can
            // outlive the file — a container filesystem without a persistent
            // volume drops uploads on every deploy — and a link to a file that
            // is no longer there looks like a broken page rather than a lost
            // upload. The panel uses this to say which one it is.
            'available' => Storage::exists($file->path),
        ];
    }

    /**
     * Route name used to build proof-document download links.
     *
     * Kept overridable because the same controller also backs the
     * session-authenticated web panel: the browser opens that link with a
     * session cookie, so it has to point at the web route rather than at
     * the token-protected API one.
     */
    protected function proofFileRouteName(): string
    {
        return 'admin.organizations.proof-file';
    }

    /**
     * Deliver the review outcome to the organization's own administrators.
     *
     * Recipients are the user ACCOUNTS that administer the organization, so
     * the mail goes to the address that owns the membership — not to
     * `contact_email`, which is a public contact address the organization may
     * not control. Memberships marked 'removed' are skipped, matching the
     * pivot-status filter used everywhere else the organization admin is
     * resolved (AuthController, the organization's own profile endpoint).
     *
     * Every outcome is logged. Laravel's mail channel returns silently when a
     * notifiable has no routable address, and this loop does nothing at all
     * when no membership matches — so without a trace here, "the approval
     * email never arrived" is indistinguishable from "there was nobody to
     * send it to", and there is nothing to debug against. The recipient list
     * and the skipped memberships are the two facts needed to tell those
     * apart.
     */
    private function notifyOrganizationAdmins(Organization $organization, $notification): void
    {
        $members = $organization->members()
            ->wherePivot('role_in_org', 'admin')
            ->get();

        $recipients = [];
        $skipped = [];

        foreach ($members as $member) {
            if ($member->pivot->status !== 'active') {
                $skipped[] = "{$member->email} (membership {$member->pivot->status})";

                continue;
            }

            if (! $member->email) {
                $skipped[] = "#{$member->id} (no email address on the account)";

                continue;
            }

            $recipients[] = $member->email;
        }

        if ($recipients === []) {
            Log::warning('Organization review notification has no recipient.', [
                'organization_id' => $organization->id,
                'notification' => $notification::class,
                'skipped' => $skipped,
            ]);

            return;
        }

        Log::info('Organization review notification dispatched.', [
            'organization_id' => $organization->id,
            'notification' => $notification::class,
            'recipients' => $recipients,
            'skipped' => $skipped,
        ]);

        foreach ($members as $member) {
            if (in_array($member->email, $recipients, true)) {
                $member->notify($notification);
            }
        }
    }

    /**
     * Clamp the requested page size so a caller cannot ask for an unbounded
     * result set.
     *
     * This list used to pass `per_page` straight into `paginate()`, so
     * `?per_page=1000000` asked the database for a million organizations in one
     * response — the one list endpoint on the API without a ceiling. Default 15
     * and a hard maximum of 100 mirror Admin\ProjectController and
     * Admin\SupportController, which already clamp the same way.
     */
    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        return max(1, min($perPage, 100));
    }
}
