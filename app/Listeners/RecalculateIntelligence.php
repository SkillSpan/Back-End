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
 *
 * ⚠️ `ShouldQueueAfterCommit` is the interface that actually defers the
 * job until the surrounding transaction commits. This class previously
 * declared a plain `afterCommit(): bool` METHOD returning true, which
 * Laravel does NOT read: the dispatcher checks
 * `property_exists($listener, 'afterCommit')` — a method is not a
 * property — so the flag resolved to null and the after-commit
 * guarantee was silently absent. Do not replace the interface with a
 * method again.
 */
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
