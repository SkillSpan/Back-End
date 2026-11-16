<?php

namespace App\Services\Projects;

use App\Exceptions\SubmissionException;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Submission;
use App\Models\User;
use App\Services\Evaluation\FirstPassCheckService;
use App\Traits\AuditsActions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SubmissionService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly MilestoneService $milestones,
        private readonly FirstPassCheckService $firstPass,
    ) {}

    public function create(
        Project $project,
        User $contributor,
        array $data,
        ?UploadedFile $file = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $this->access->assertMember($project, $contributor);
        $this->assertDeliverableWindowOpen($project);

        $type = (string) ($data['submission_type'] ?? '');
        $this->assertSupportedType($type);

        $milestone = $this->resolveMilestone($project, $data['project_milestone_id'] ?? null);

        if ($milestone !== null) {
            $this->assertMilestoneAcceptsSubmission($milestone, $type);
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($data['idempotency_key'] ?? null);

        if ($idempotencyKey !== null) {
            $existing = Submission::query()
                ->where('contributor_id', $contributor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return ['submission' => $existing->fresh(), 'replayed' => true];
            }
        }

        [$linkUrl, $filePath, $originalName, $fileSize, $contentType] = $this->resolvePayload($type, $data, $file);

        $storedPath = null;

        if ($filePath !== null) {
            $storedPath = $filePath;
        }

        try {
            $submission = DB::transaction(function () use (
                $project,
                $milestone,
                $contributor,
                $type,
                $data,
                $linkUrl,
                $storedPath,
                $originalName,
                $fileSize,
                $contentType,
                $idempotencyKey,
            ): Submission {
                $version = $this->nextVersion($project, $milestone, $contributor);

                Submission::query()
                    ->where('project_id', $project->id)
                    ->where('contributor_id', $contributor->id)
                    ->where('is_current', true)
                    ->when(
                        $milestone === null,
                        fn ($q) => $q->whereNull('project_milestone_id'),
                        fn ($q) => $q->where('project_milestone_id', $milestone->id),
                    )
                    ->update(['is_current' => false, 'status' => Submission::STATUS_SUPERSEDED]);

                return Submission::create([
                    'project_id' => $project->id,
                    'project_milestone_id' => $milestone?->id,
                    'project_team_id' => $project->currentTeam()?->id,
                    'contributor_id' => $contributor->id,
                    'version' => $version,
                    'submission_type' => $type,
                    'notes' => $data['notes'] ?? null,
                    'link_url' => $linkUrl,
                    'file_path' => $storedPath,
                    'original_filename' => $originalName,
                    'file_size' => $fileSize,
                    'content_type' => $contentType,
                    'status' => Submission::STATUS_SUBMITTED,
                    'is_current' => true,
                    'idempotency_key' => $idempotencyKey,
                    'first_pass_status' => Submission::FIRST_PASS_PENDING,
                    'submitted_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk($this->disk())->delete($storedPath);
            }

            throw $e;
        }

        if ($milestone !== null) {
            $this->milestones->markSubmitted($milestone, $submission);
        }

        $this->audit(
            $contributor->id,
            'submission.created',
            'submission',
            $submission->id,
            null,
            [
                'project_id' => $project->id,
                'milestone_id' => $milestone?->id,
                'version' => $submission->version,
                'submission_type' => $type,
                'file_name' => $originalName,
            ],
            'project_delivery',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->firstPass->queue($submission);

        return ['submission' => $submission->fresh(), 'replayed' => false];
    }

    public function history(Project $project, User $viewer, ?int $milestoneId = null, bool $onlyMine = false): Collection
    {
        $this->access->assertMemberOrManager($project, $viewer);

        return Submission::query()
            ->where('project_id', $project->id)
            ->when($milestoneId !== null, fn ($q) => $q->where('project_milestone_id', $milestoneId))
            ->when($milestoneId === null && $onlyMine, fn ($q) => $q->where('contributor_id', $viewer->id))
            ->when($onlyMine, fn ($q) => $q->where('contributor_id', $viewer->id))
            ->with(['contributor', 'firstPassCheck', 'milestone'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();
    }

    public function findForViewer(int $submissionId, User $viewer): Submission
    {
        $submission = Submission::query()
            ->with(['contributor', 'firstPassCheck', 'milestone', 'project'])
            ->find($submissionId);

        if ($submission === null || $submission->project === null) {
            throw new SubmissionException(
                'The requested submission does not exist.',
                404,
                'SUBMISSION_NOT_FOUND',
                ['submission_id' => $submissionId],
            );
        }

        $this->access->assertMemberOrManager($submission->project, $viewer);

        return $submission;
    }

    public function payload(Submission $submission): array
    {
        $check = $submission->relationLoaded('firstPassCheck')
            ? $submission->firstPassCheck
            : $submission->firstPassCheck()->first();

        return [
            'id' => $submission->id,
            'project_id' => $submission->project_id,
            'milestone_id' => $submission->project_milestone_id,
            'milestone_title' => $submission->milestone?->title,
            'version' => (int) $submission->version,
            'contributor' => [
                'id' => $submission->contributor_id,
                'name' => $submission->contributor?->name,
            ],
            'submission_type' => $submission->submission_type,
            'link_url' => $submission->link_url,
            'file' => $submission->file_path === null ? null : [
                'name' => $submission->original_filename,
                'size' => $submission->file_size,
                'content_type' => $submission->content_type,
                'download_url' => '/api/v1/submissions/'.$submission->id.'/file',
            ],
            'notes' => $submission->notes,
            'status' => $submission->status,
            'is_current' => (bool) $submission->is_current,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'first_pass' => $check === null ? null : [
                'status' => $check->status,
                'items' => $check->items ?? [],
                'notes' => $check->notes,
                'completed_at' => $check->completed_at?->toIso8601String(),
            ],
        ];
    }

    public function disk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    private function assertDeliverableWindowOpen(Project $project): void
    {
        if (! in_array($project->status, [Project::STATUS_ACTIVE, Project::STATUS_UNDER_REVIEW], true)) {
            throw new SubmissionException(
                'Deliverables can only be submitted while the project is active.',
                422,
                'SUBMISSION_WINDOW_CLOSED',
                ['project_id' => $project->id, 'status' => $project->status],
            );
        }
    }

    private function assertSupportedType(string $type): void
    {
        if (! in_array($type, Submission::TYPES, true)) {
            throw new SubmissionException(
                'The selected submission type is not supported.',
                422,
                'SUBMISSION_TYPE_UNSUPPORTED',
                ['allowed' => Submission::TYPES],
            );
        }
    }

    private function resolveMilestone(Project $project, mixed $milestoneId): ?ProjectMilestone
    {
        if ($milestoneId === null) {
            return null;
        }

        $milestone = $project->milestones()->whereKey((int) $milestoneId)->first();

        if ($milestone === null) {
            throw new SubmissionException(
                'The selected milestone does not belong to this project.',
                404,
                'MILESTONE_NOT_FOUND',
                ['milestone_id' => $milestoneId, 'project_id' => $project->id],
            );
        }

        return $milestone;
    }

    private function assertMilestoneAcceptsSubmission(ProjectMilestone $milestone, string $type): void
    {
        if ($milestone->status === ProjectMilestone::STATUS_COMPLETED) {
            throw new SubmissionException(
                'This milestone is already completed and cannot receive new submissions.',
                422,
                'MILESTONE_COMPLETED',
                ['milestone_id' => $milestone->id],
            );
        }

        if ($milestone->hasPassedDeadline()) {
            throw new SubmissionException(
                'The effective deadline for this milestone has passed. Request an extension to submit again.',
                422,
                'SUBMISSION_DEADLINE_PASSED',
                [
                    'milestone_id' => $milestone->id,
                    'effective_deadline' => $milestone->effectiveDeadline()?->toDateString(),
                ],
            );
        }

        if (! $milestone->allowsSubmissionType($type)) {
            throw new SubmissionException(
                'This milestone only accepts a different submission type.',
                422,
                'SUBMISSION_TYPE_NOT_ALLOWED',
                [
                    'milestone_id' => $milestone->id,
                    'allowed_type' => $milestone->submission_type,
                    'requested_type' => $type,
                ],
            );
        }
    }

    private function resolvePayload(string $type, array $data, ?UploadedFile $file): array
    {
        if (in_array($type, Submission::FILE_TYPES, true)) {
            if (! $file instanceof UploadedFile) {
                throw new SubmissionException(
                    'A file is required for this submission type.',
                    422,
                    'SUBMISSION_FILE_REQUIRED',
                    ['submission_type' => $type],
                );
            }

            $this->assertFileAcceptable($file);

            $originalName = $this->safeFilename($file->getClientOriginalName());
            $directory = 'submissions/'.Str::uuid();
            $path = $file->storeAs($directory, $originalName, $this->disk());

            if ($path === false) {
                throw new SubmissionException(
                    'The submitted file could not be stored.',
                    500,
                    'SUBMISSION_STORAGE_FAILED',
                );
            }

            return [null, $path, $originalName, (int) $file->getSize(), (string) $file->getMimeType()];
        }

        $link = trim((string) ($data['link_url'] ?? ''));

        if ($link === '') {
            throw new SubmissionException(
                'A link is required for this submission type.',
                422,
                'SUBMISSION_LINK_REQUIRED',
                ['submission_type' => $type],
            );
        }

        if (! filter_var($link, FILTER_VALIDATE_URL) || ! in_array(Str::lower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new SubmissionException(
                'The submitted link is not a valid http or https address.',
                422,
                'SUBMISSION_LINK_INVALID',
                ['link_url' => $link],
            );
        }

        return [$link, null, null, null, null];
    }

    private function assertFileAcceptable(UploadedFile $file): void
    {
        $maxKb = (int) config('sprint5.submissions.max_file_kb', 20480);

        if ((int) $file->getSize() > $maxKb * 1024) {
            throw new SubmissionException(
                'The submitted file is larger than the allowed limit.',
                422,
                'SUBMISSION_FILE_TOO_LARGE',
                ['max_kb' => $maxKb],
            );
        }

        $extension = Str::lower((string) $file->getClientOriginalExtension());
        $allowedExtensions = array_map('strtolower', (array) config('sprint5.submissions.allowed_extensions', []));

        if (! in_array($extension, $allowedExtensions, true)) {
            throw new SubmissionException(
                'This file format is not accepted for deliverables.',
                422,
                'SUBMISSION_FILE_TYPE_NOT_ALLOWED',
                ['extension' => $extension, 'allowed' => $allowedExtensions],
            );
        }

        $mime = (string) $file->getMimeType();
        $allowedMimes = (array) config('sprint5.submissions.allowed_mime_types', []);

        if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
            throw new SubmissionException(
                'This file format is not accepted for deliverables.',
                422,
                'SUBMISSION_FILE_TYPE_NOT_ALLOWED',
                ['content_type' => $mime, 'allowed' => $allowedMimes],
            );
        }
    }

    private function safeFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? 'deliverable';
        $base = ltrim($base, '.');
        $base = Str::limit($base === '' ? 'deliverable' : $base, 120, '');

        return $base === '' ? 'deliverable' : $base;
    }

    private function normalizeIdempotencyKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $value = trim((string) $key);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 120) {
            throw new SubmissionException(
                'The idempotency key is too long.',
                422,
                'SUBMISSION_IDEMPOTENCY_KEY_INVALID',
                ['max_length' => 120],
            );
        }

        return $value;
    }

    private function nextVersion(Project $project, ?ProjectMilestone $milestone, User $contributor): int
    {
        $max = Submission::query()
            ->where('project_id', $project->id)
            ->where('contributor_id', $contributor->id)
            ->when(
                $milestone === null,
                fn ($q) => $q->whereNull('project_milestone_id'),
                fn ($q) => $q->where('project_milestone_id', $milestone->id),
            )
            ->max('version');

        $limit = (int) config('sprint5.submissions.max_versions_per_target', 50);

        if ((int) $max >= $limit) {
            throw new SubmissionException(
                'This deliverable already has the maximum number of versions.',
                422,
                'SUBMISSION_VERSION_LIMIT_REACHED',
                ['limit' => $limit],
            );
        }

        return (int) $max + 1;
    }
}
