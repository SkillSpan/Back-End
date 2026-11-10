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

class OrganizationController extends Controller
{
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

    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully.',
            'data' => $this->transform($organization),
        ]);
    }

    public function approve(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->verification_status !== 'pending') {
            return $this->alreadyReviewedResponse($organization);
        }

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

    private function claimPendingOrganization(Organization $organization, int $actorId, string $status): bool
    {
        $claimed = Organization::whereKey($organization->getKey())
            ->where('verification_status', 'pending')
            ->update([
                'verification_status' => $status,
                'verified_at' => $status === 'verified' ? now() : null,
                'verified_by' => $actorId,
            ]);

        if ($claimed === 0) {
            return false;
        }

        $organization->refresh();

        return true;
    }

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
            'available' => Storage::exists($file->path),
        ];
    }

    protected function proofFileRouteName(): string
    {
        return 'admin.organizations.proof-file';
    }

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

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        return max(1, min($perPage, 100));
    }
}
