<?php

namespace App\Listeners;

use App\Events\SkillDataChanged;
use App\Services\Intelligence\IntelligenceService;
use App\Support\SafeLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecalculateIntelligence implements ShouldQueue, ShouldQueueAfterCommit
{
    public function __construct(
        private readonly IntelligenceService $intelligenceService,
    ) {}

    public string $queue = 'default';

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(SkillDataChanged $event): void
    {
        $profile = $event->studentProfile->fresh();

        if (! $profile) {
            return;
        }

        try {
            $this->intelligenceService->calculate(
                $profile,
                null,
                'recalc-'.(string) Str::uuid(),
            );
        } catch (Throwable $e) {
            Log::warning('Queued intelligence recalculation failed.', [
                'source' => $event->source,
                'student_profile_id' => $profile->id,
                'failure_reason' => SafeLog::reason($e),
            ]);
        }
    }
}
