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

        $organizations = $query->paginate((int) $request->query('per_page', 15));

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
            return response()->json([
                'success' => false,
                'message' => 'This organization has already been reviewed.',
                'data' => [
                    'verification_status' => $organization->verification_status,
                ],
            ], 422);
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

            $organization->forceFill([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
            ])->save();

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
            return response()->json([
                'success' => false,
                'message' => 'This organization has already been reviewed.',
                'data' => [
                    'verification_status' => $organization->verification_status,
                ],
            ], 422);
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

            $organization->forceFill([
                'verification_status' => 'rejected',
                'verified_at' => null,
                'verified_by' => $request->user()->id,
            ])->save();

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

        if (! $file || ! Storage::disk('local')->exists($file->path)) {
            return response()->json([
                'success' => false,
                'message' => 'Proof file not found.',
            ], 404);
        }

        return Storage::disk('local')->response($file->path, null, [
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
            'download_url' => route('admin.organizations.proof-file', $file->fileable_id),
        ];
    }

    private function notifyOrganizationAdmins(Organization $organization, $notification): void
    {
        $admins = $organization->members()
            ->wherePivot('role_in_org', 'admin')
            ->get();

        foreach ($admins as $admin) {
            $admin->notify($notification);
        }
    }
}
