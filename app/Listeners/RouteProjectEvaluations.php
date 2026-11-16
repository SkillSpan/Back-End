<?php

namespace App\Listeners;

use App\Events\ProjectEnteredReview;
use App\Services\Evaluation\EvaluationRoutingService;
use App\Support\SafeLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouteProjectEvaluations
{
    public function __construct(private readonly EvaluationRoutingService $routing) {}

    public function handle(ProjectEnteredReview $event): void
    {
        try {
            $this->routing->routeProject($event->project->fresh());
        } catch (Throwable $e) {
            Log::warning('Evaluator routing failed after entering review.', [
                'project_id' => $event->project->id,
                'failure_reason' => SafeLog::reason($e),
            ]);
        }
    }
}
