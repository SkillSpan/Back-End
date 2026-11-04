<?php

namespace App\Http\Controllers\Api\Company;

use App\Exceptions\ProfileException;
use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateOrganizationProfileRequest;
use App\Models\Organization;
use App\Services\Company\OrganizationProfileService;
use App\Traits\AuditsActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * US-COMP-04 — Organization Profile Management.
 *
 * GET    /api/v1/profile/me                     (company)  — the SRS profile endpoint
 * GET    /api/v1/organization/profile           (company)  — same payload, existing path
 * PATCH  /api/v1/profile/me                     (company)
 * POST   /api/v1/company/profile/documents      (company)
 * GET    /api/v1/company/profile/documents/{id} (company)
 *
 * Every route sits behind auth:sanctum + account.active + organization.approved
 * + role:company_admin, and the organization is resolved from the caller's own
 * admin membership (ResolvesCompanyOrganization) — never from the request, so
 * there is no id to tamper with.
 */
class OrganizationProfileController extends Controller
{
    use AuditsActions;
    use ResolvesCompanyOrganization;

    public function __construct(private readonly OrganizationProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        return $this->ok($this->profiles->payload($organization), 'Organization profile retrieved successfully.');
    }

    public function update(UpdateOrganizationProfileRequest $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        $data = $request->validated();

        try {
            // Read-only fields first: sending a *different* verification_status
            // is an attempt to edit something SkillSpan owns (BR-03), and the
            // caller deserves to be told that rather than have it ignored.
            $this->profiles->guardReadOnly($organization, $request->all());
            $this->profiles->guardRequiredContact($organization, $data);

            $result = $this->profiles->update($organization, $request->user(), $data);
        } catch (ProfileException $e) {
            return $this->profileError($e);
        }

        if ($result['changed'] !== []) {
            $this->audit(
                actorId: $request->user()->id,
                action: 'organization.profile.updated',
                entityType: 'organization',
                entityId: $organization->id,
                before: array_map(static fn (array $change) => $change['from'], $result['changed']),
                after: array_merge(
                    array_map(static fn (array $change) => $change['to'], $result['changed']),
                    [
                        'reverification_required' => $result['reverification_triggered'],
                        'completeness' => $result['profile']['profile_completeness'],
                        'completeness_version' => $result['profile']['completeness']['version'],
                    ],
                ),
                requestId: $request->attributes->get('request_id'),
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );
        }

        return $this->ok([
            'profile' => $result['profile'],
            'changed' => array_keys($result['changed']),
            'reverification_triggered' => $result['reverification_triggered'],
            'reverification' => $result['reverification'],
        ], 'Organization profile updated successfully.');
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        $type = (string) $request->input('type', '');
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return $this->documentError('No document was uploaded.', 'DOCUMENT_MISSING');
        }

        // PHP-level failures first: a truncated upload (bigger than
        // upload_max_filesize) would otherwise be reported as "wrong type",
        // because the partial bytes do not look like a PDF.
        if (! $file->isValid()) {
            return $this->documentError(
                $this->uploadErrorMessage($file->getError()),
                $file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
                    ? 'DOCUMENT_TOO_LARGE'
                    : 'DOCUMENT_UPLOAD_FAILED',
            );
        }

        $maxKb = (int) config('company.profile.documents.max_kb', 5120);

        if ((int) $file->getSize() > $maxKb * 1024) {
            return $this->documentError(
                sprintf('The document is too large. The limit is %s MB.', round($maxKb / 1024, 1)),
                'DOCUMENT_TOO_LARGE',
            );
        }

        $allowedMimes = array_map('strtolower', (array) config('company.profile.documents.mimes', []));
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedMimes, true)) {
            return $this->documentError(
                'Unsupported file type. Allowed: '.implode(', ', $allowedMimes).'.',
                'DOCUMENT_TYPE_NOT_SUPPORTED',
            );
        }

        // The extension is what the client claims; the detected MIME is what
        // the bytes actually are. Requiring both stops an .exe renamed to .pdf.
        $detected = (string) $file->getMimeType();
        $allowedContentTypes = (array) config('company.profile.documents.mime_types', []);

        if ($allowedContentTypes !== [] && ! in_array($detected, $allowedContentTypes, true)) {
            return $this->documentError(
                'The file content does not match its extension. Please upload a real PDF or image.',
                'DOCUMENT_TYPE_NOT_SUPPORTED',
            );
        }

        try {
            $this->profiles->documentDefinition($type);
            $result = $this->profiles->uploadDocument($organization, $request->user(), $type, $file);
        } catch (ProfileException $e) {
            return $this->profileError($e);
        }

        $this->audit(
            actorId: $request->user()->id,
            action: 'organization.document.uploaded',
            entityType: 'organization',
            entityId: $organization->id,
            before: $result['replaced'] === null ? null : [
                'document_id' => $result['replaced']['id'],
                'version' => $result['replaced']['version'],
            ],
            after: [
                'document_id' => $result['document']['id'],
                'type' => $result['document']['type'],
                'version' => $result['document']['version'],
                'replaces_id' => $result['replaced']['id'] ?? null,
                'mime_type' => $result['document']['mime_type'],
                'size' => $result['document']['size'],
                'reverification_required' => $result['reverification_triggered'],
            ],
            requestId: $request->attributes->get('request_id'),
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return $this->ok([
            'document' => $result['document'],
            'replaced' => $result['replaced'],
            'profile' => $result['profile'],
            'reverification_triggered' => $result['reverification_triggered'],
            'reverification' => $result['reverification'],
        ], $result['replaced'] === null ? 'Document uploaded successfully.' : 'Document replaced successfully.');
    }

    /**
     * Upload or replace the organization logo.
     *
     * Field name is `logo`, not `file` — this is a distinct action from the
     * document upload and reusing `file` would make the two indistinguishable in
     * logs and in the frontend's FormData.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        $file = $request->file('logo');

        if (! $file instanceof UploadedFile) {
            return $this->documentError('No logo was uploaded.', 'LOGO_MISSING');
        }

        // PHP-level failures first, for the same reason the document upload
        // does it: a truncated upload does not look like an image.
        if (! $file->isValid()) {
            return $this->documentError(
                $this->uploadErrorMessage($file->getError()),
                $file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
                    ? 'LOGO_TOO_LARGE'
                    : 'LOGO_UPLOAD_FAILED',
            );
        }

        try {
            $result = $this->profiles->uploadLogo($organization, $request->user(), $file);
        } catch (ProfileException $e) {
            return $this->profileError($e);
        }

        $this->audit(
            actorId: $request->user()->id,
            action: 'organization.logo.uploaded',
            entityType: 'organization',
            entityId: $organization->id,
            after: ['logo' => $result['logo']],
            requestId: $request->attributes->get('request_id'),
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return $this->ok([
            'logo' => $result['logo'],
            'logo_url' => $result['logo_url'],
            'profile' => $result['profile'],
        ], 'Logo updated successfully.');
    }

    /**
     * Remove the organization logo. Idempotent.
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        $result = $this->profiles->removeLogo($organization);

        $this->audit(
            actorId: $request->user()->id,
            action: 'organization.logo.removed',
            entityType: 'organization',
            entityId: $organization->id,
            after: ['logo' => null],
            requestId: $request->attributes->get('request_id'),
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return $this->ok([
            'logo' => $result['logo'],
            'logo_url' => $result['logo_url'],
            'profile' => $result['profile'],
        ], 'Logo removed successfully.');
    }

    /**
     * BR-07 / AC-08 — a document is readable only by the organization that owns
     * it. The lookup goes through that organization's own files(), so another
     * company's document is a 404 rather than a 403: it does not exist for
     * this caller.
     */
    public function downloadDocument(Request $request, int $document): StreamedResponse|JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->deny($request, 'company.profile.denied');
        }

        $file = $this->profiles->findDocument($organization, $document);

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'This document does not exist.',
                'code' => 'DOCUMENT_NOT_FOUND',
            ], 404);
        }

        $disk = Storage::disk($this->profiles->disk());

        if (! $disk->exists((string) $file->path)) {
            return response()->json([
                'success' => false,
                'message' => 'The document is no longer available on storage.',
                'code' => 'DOCUMENT_UNAVAILABLE',
            ], 404);
        }

        return $disk->download(
            (string) $file->path,
            $file->original_name ?: (Str::afterLast((string) $file->path, '/')),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function deny(Request $request, string $action): JsonResponse
    {
        $denial = $this->organizationDenial($request);

        $this->audit(
            actorId: $request->user()->id,
            action: $action,
            entityType: 'organization',
            entityId: null,
            after: ['reason' => $denial['reason']],
            requestId: $request->attributes->get('request_id'),
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return response()->json([
            'success' => false,
            'message' => $denial['code'] === 'NO_ORGANIZATION'
                ? 'This account is not linked to any organization.'
                : 'Only an organization admin can manage this profile.',
            'code' => $denial['code'],
        ], 403);
    }

    private function profileError(ProfileException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'code' => $e->codeName,
            'errors' => $e->details,
        ], $e->status);
    }

    private function documentError(string $message, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], 422);
    }

    private function uploadErrorMessage(int $error): string
    {
        $maxKb = (int) config('company.profile.documents.max_kb', 5120);

        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'The document is too large to upload (limit %s MB).',
                round($maxKb / 1024, 1),
            ),
            UPLOAD_ERR_PARTIAL => 'The upload was cut off before it finished. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No document was received. Please pick a file and try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Please try again in a moment.',
            UPLOAD_ERR_EXTENSION => 'The document was rejected by the server. Please try a PDF, JPG or PNG.',
            default => 'The document could not be uploaded. Please try again.',
        };
    }
}
