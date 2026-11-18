<?php

namespace App\Console\Commands;

use App\Events\SkillDataChanged;
use App\Models\BaselineAssessment;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Services\Skills\SkillEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildBaselineEvidence extends Command
{
    protected $signature = 'skills:rebuild-baseline-evidence {profile : Explicit student profile ID} {--apply : Append canonical evaluations; otherwise preview only}';

    protected $description = 'Rebuild one profile from existing baseline evidence without rewriting attempts or self-assessments';

    public function handle(SkillEvaluationService $service): int
    {
        $id = (string) $this->argument('profile');
        if (! preg_match('/\A[1-9][0-9]*\z/', $id) || ! ($profile = StudentProfile::find($id))) {
            $this->error('Provide an existing student profile ID.');

            return self::FAILURE;
        }
        $attemptIds = BaselineAssessment::where('student_profile_id', $profile->id)
            ->where('status', 'completed')->pluck('id');
        $skills = Skill::whereIn('id', SkillEvidence::where('student_profile_id', $profile->id)
            ->where('source_record_type', BaselineAssessment::class)
            ->whereIn('source_record_id', $attemptIds)->pluck('skill_id'))->orderBy('id')->get();
        $this->line('Profile '.$profile->id.': '.$skills->count().' skills with completed baseline evidence.');
        if (! $this->option('apply')) {
            $this->info('Preview only: no data changed. Use --apply to append canonical evaluations.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($skills, $profile, $service) {
            foreach ($skills as $skill) {
                $result = $service->recalculate($profile, $skill, false);
                $this->line('Skill '.$skill->id.': level '.$result->level.', evidence confidence '.$result->confidence.'%.');
            }
        });
        if ($skills->isNotEmpty()) {
            SkillDataChanged::dispatch($profile, 'baseline_evidence_rebuild');
        }
        $this->info('Canonical evaluations appended; attempts, evidence and stored self-assessments preserved.');

        return self::SUCCESS;
    }
}
