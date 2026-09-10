<?php

namespace App\Listeners;

use App\Events\SkillDataChanged;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * US-INT-01 §24 — authorized intelligence recalculation after an
 * approved skill-data change. Queued + afterCommit so the original
 * HTTP request never blocks or breaks on a recalculation failure.
 *
 * Idempotent by design: a recalculation appends a NEW historical
 * decision; duplicates from a double-fired event only produce an extra
 * reproducible decision, never corrupted data. Failures are logged
 * and swallowed — the triggering flow already committed its own
 * transaction and must not be rolled back by this listener.
 */
class RecalculateIntelligence implements ShouldQueue
{
    public function __construct(
        private readonly IntelligenceService $intelligenceService,
    ) {}

    public string $queue = 'default';

    public function viaQueue(): string
    {
        return 'default';
    }

    public function afterCommit(): bool
    {
        return true;
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
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
