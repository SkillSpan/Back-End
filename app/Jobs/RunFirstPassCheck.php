<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\Evaluation\FirstPassCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunFirstPassCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $submissionId) {}

    public function handle(FirstPassCheckService $service): void
    {
        $submission = Submission::query()->find($this->submissionId);

        if ($submission === null) {
            return;
        }

        $service->run($submission);
    }

    public function failed(Throwable $exception): void
    {
        $submission = Submission::query()->find($this->submissionId);

        if ($submission === null) {
            return;
        }

        $submission->firstPassCheck()->update([
            'status' => 'error',
            'notes' => 'The automated check could not be completed. An evaluator will review the submission.',
            'completed_at' => now(),
        ]);

        $submission->forceFill(['first_pass_status' => 'error'])->save();
    }
}
