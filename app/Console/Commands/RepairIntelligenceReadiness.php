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
