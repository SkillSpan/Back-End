<?php

namespace App\Services\Evaluation;

use App\Jobs\RunFirstPassCheck;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Submission;
use App\Models\SubmissionFirstPassCheck;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FirstPassCheckService
{
    public function __construct(private readonly EvaluationRoutingService $routing) {}

    public function queue(Submission $submission): void
    {
        SubmissionFirstPassCheck::firstOrCreate(
            ['submission_id' => $submission->id],
            ['status' => SubmissionFirstPassCheck::STATUS_PENDING],
        );

        RunFirstPassCheck::dispatch($submission->id);
    }

    public function run(Submission $submission): SubmissionFirstPassCheck
    {
        $check = SubmissionFirstPassCheck::firstOrCreate(
            ['submission_id' => $submission->id],
            ['status' => SubmissionFirstPassCheck::STATUS_PENDING],
        );

        if (in_array($check->status, [
            SubmissionFirstPassCheck::STATUS_PASSED,
            SubmissionFirstPassCheck::STATUS_FAILED,
        ], true)) {
            return $check;
        }

        $check->forceFill([
            'status' => SubmissionFirstPassCheck::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $items = $this->items($submission);
        $failed = collect($items)->where('status', 'fail')->isNotEmpty();

        $check->forceFill([
            'status' => $failed
                ? SubmissionFirstPassCheck::STATUS_FAILED
                : SubmissionFirstPassCheck::STATUS_PASSED,
            'items' => $items,
            'notes' => $failed
                ? 'Some automated checks need your attention before human evaluation.'
                : 'Received and passed the automated first-pass check.',
            'completed_at' => now(),
        ])->save();

        $submission->forceFill(['first_pass_status' => $check->status])->save();

        if (! $failed) {
            $this->routeWhenReady($submission);
        }

        return $check->fresh();
    }

    public function payload(Submission $submission): array
    {
        $check = $submission->firstPassCheck()->first();

        if ($check === null) {
            return [
                'submission_id' => $submission->id,
                'status' => SubmissionFirstPassCheck::STATUS_PENDING,
                'items' => [],
                'notes' => null,
                'completed_at' => null,
            ];
        }

        return [
            'submission_id' => $submission->id,
            'status' => $check->status,
            'items' => $check->items ?? [],
            'notes' => $check->notes,
            'started_at' => $check->started_at?->toIso8601String(),
            'completed_at' => $check->completed_at?->toIso8601String(),
        ];
    }

    private function routeWhenReady(Submission $submission): void
    {
        $project = $submission->project;

        if ($project === null || $project->status !== Project::STATUS_UNDER_REVIEW) {
            return;
        }

        if ($submission->project_milestone_id !== null) {
            return;
        }

        $this->routing->route($project, (int) $submission->contributor_id, $submission);
    }

    private function items(Submission $submission): array
    {
        $items = [];

        $hasReference = $submission->isFileBased()
            ? is_string($submission->file_path) && $submission->file_path !== ''
            : is_string($submission->link_url) && $submission->link_url !== '';

        $items[] = [
            'key' => 'reference_present',
            'label' => 'Deliverable reference provided',
            'status' => $hasReference ? 'pass' : 'fail',
            'message' => $hasReference ? 'A deliverable reference is attached.' : 'No deliverable file or link was provided.',
        ];

        if ($submission->isFileBased()) {
            $exists = $hasReference && Storage::disk((string) config('filesystems.default', 'local'))
                ->exists((string) $submission->file_path);

            $items[] = [
                'key' => 'file_available',
                'label' => 'File is stored and readable',
                'status' => $exists ? 'pass' : 'fail',
                'message' => $exists ? 'The uploaded file is available in storage.' : 'The uploaded file could not be read from storage.',
            ];

            $extension = Str::lower((string) pathinfo((string) $submission->original_filename, PATHINFO_EXTENSION));
            $allowed = array_map('strtolower', (array) config('sprint5.submissions.allowed_extensions', []));
            $supported = $extension !== '' && in_array($extension, $allowed, true);

            $items[] = [
                'key' => 'format_supported',
                'label' => 'File format is supported',
                'status' => $supported ? 'pass' : 'fail',
                'message' => $supported
                    ? 'The file format is on the accepted list.'
                    : 'The file format is not on the accepted list.',
            ];
        } else {
            $scheme = Str::lower((string) parse_url((string) $submission->link_url, PHP_URL_SCHEME));
            $supported = filter_var((string) $submission->link_url, FILTER_VALIDATE_URL) !== false
                && in_array($scheme, ['http', 'https'], true);

            $items[] = [
                'key' => 'format_supported',
                'label' => 'Link format is supported',
                'status' => $supported ? 'pass' : 'fail',
                'message' => $supported
                    ? 'The link is a valid http or https address.'
                    : 'The link is not a valid http or https address.',
            ];
        }

        $notes = trim((string) $submission->notes);

        $items[] = [
            'key' => 'notes_provided',
            'label' => 'Submission notes provided',
            'status' => $notes === '' ? 'warn' : 'pass',
            'message' => $notes === ''
                ? 'No notes were provided with this submission.'
                : 'Submission notes were provided.',
        ];

        $milestone = $submission->milestone;

        if ($milestone instanceof ProjectMilestone) {
            $required = $milestone->requiredDeliverables();

            $items[] = [
                'key' => 'required_deliverables',
                'label' => 'Milestone deliverables declared',
                'status' => $required === [] ? 'warn' : 'pass',
                'message' => $required === []
                    ? 'This milestone does not declare required deliverables.'
                    : 'Expected deliverables: '.implode(', ', $required).'.',
            ];
        }

        return $items;
    }
}
