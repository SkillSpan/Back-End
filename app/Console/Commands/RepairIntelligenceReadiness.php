<?php

namespace App\Console\Commands;

use App\Models\BaselineAssessment;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\StudentProfile;
use App\Services\Intelligence\IntelligenceService;
use App\Support\SafeLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Repair learners whose intelligence recalculation never landed.
 *
 * The symptom reported from the live server was `GET /readiness/latest` and
 * `GET /intelligence/latest` answering 404 for a learner who had already
 * finished an assessment. Both endpoints read persisted rows — `readiness_results`
 * and a SUCCEEDED intelligence `decision_snapshots` row — and neither existed.
 *
 * Cause: the post-assessment recalculation runs in a queued listener
 * (`RecalculateIntelligence`) that deliberately swallows its own failures, and
 * the intelligence flow refuses to run without a career role
 * (`CAREER_ROLE_REQUIRED`). `student_profiles.primary_career_role_id` was never
 * written, so every recalculation aborted at the first guard, logged one
 * warning, and left nothing behind. The assessment itself succeeded, which is
 * why the 404 looked like a broken endpoint rather than a missing input.
 *
 * New assessments now record the primary role at `start()` (see
 * BaselineAssessmentService), but that does nothing for learners who already
 * assessed. This command is that backfill: it recovers the role from the
 * learner's newest completed assessment and runs the recalculation once,
 * synchronously, so the failure is reported here instead of disappearing into
 * a log line.
 *
 * Read-only unless `--force` is passed. On a shared production database that
 * distinction is the whole point.
 */
class RepairIntelligenceReadiness extends Command
{
    protected $signature = 'app:repair-intelligence-readiness
                            {--learner=* : Only repair these learner (user) ids}
                            {--force : Actually write; without it the command only reports}';

    protected $description = 'Backfill the primary career role and re-run intelligence for learners with no readiness result';

    public function handle(IntelligenceService $intelligence): int
    {
        $force = (bool) $this->option('force');
        $only = array_filter(array_map('intval', (array) $this->option('learner')));

        $profiles = StudentProfile::query()
            ->when($only !== [], fn ($query) => $query->whereIn('user_id', $only))
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('baseline_assessments')
                    ->whereColumn('baseline_assessments.student_profile_id', 'student_profiles.id')
                    ->where('baseline_assessments.status', 'completed')
                    ->whereNotNull('baseline_assessments.career_role_id');
            })
            ->orderBy('id')
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('No learner with a completed assessment was found.');

            return self::SUCCESS;
        }

        $rows = [];
        $needsRepair = [];

        foreach ($profiles as $profile) {
            $roleId = $this->resolveCareerRoleId($profile);

            $hasReadiness = ReadinessResult::query()
                ->where('student_profile_id', $profile->id)
                ->exists();

            $hasSnapshot = DecisionSnapshot::query()
                ->where('student_profile_id', $profile->id)
                ->where('status', DecisionSnapshot::STATUS_SUCCEEDED)
                ->intelligenceFlow()
                ->exists();

            $rows[] = [
                $profile->user_id,
                $profile->primary_career_role_id ?? '—',
                $roleId ?? '—',
                $hasReadiness ? 'yes' : 'NO',
                $hasSnapshot ? 'yes' : 'NO',
            ];

            if (! $hasReadiness || ! $hasSnapshot) {
                $needsRepair[] = [$profile, $roleId];
            }
        }

        $this->table(
            ['learner_id', 'primary_role', 'recovered_role', 'readiness', 'intelligence'],
            $rows,
        );

        if ($needsRepair === []) {
            $this->info('Every learner already has a readiness result and an intelligence snapshot.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn(count($needsRepair).' learner(s) need a repair. Re-run with --force to apply it.');

            return self::SUCCESS;
        }

        $repaired = 0;
        $failed = 0;

        foreach ($needsRepair as [$profile, $roleId]) {
            if ($roleId === null) {
                $this->error("Learner {$profile->user_id}: no career role could be recovered.");

                $failed++;

                continue;
            }

            if ((int) $profile->primary_career_role_id !== $roleId) {
                $profile->forceFill(['primary_career_role_id' => $roleId])->save();
            }

            try {
                $intelligence->calculate($profile->fresh(), null, 'repair-'.(string) Str::uuid());

                $this->line("Learner {$profile->user_id}: repaired.");

                $repaired++;
            } catch (Throwable $e) {
                $this->error("Learner {$profile->user_id}: ".SafeLog::reason($e));

                $failed++;
            }
        }

        $this->info("Repaired {$repaired}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The role the learner was last assessed against.
     *
     * The primary role is preferred when it is already set; otherwise the
     * newest completed assessment supplies it. `career_role_id` is nullable on
     * that table, hence the explicit filter.
     */
    private function resolveCareerRoleId(StudentProfile $profile): ?int
    {
        if ($profile->primary_career_role_id) {
            return (int) $profile->primary_career_role_id;
        }

        $roleId = BaselineAssessment::query()
            ->where('student_profile_id', $profile->id)
            ->where('status', 'completed')
            ->whereNotNull('career_role_id')
            ->latest('completed_at')
            ->latest('id')
            ->value('career_role_id');

        return $roleId !== null ? (int) $roleId : null;
    }
}
