<?php

namespace App\Listeners;

use App\Events\EvaluationFinalized;
use App\Models\ProfessionalRecordItem;
use App\Models\StudentProfile;
use App\Support\SafeLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncProfessionalRecord
{
    public function handle(EvaluationFinalized $event): void
    {
        $evaluation = $event->evaluation;

        if ($evaluation->participant_id === null) {
            return;
        }

        try {
            $profile = StudentProfile::query()
                ->where('user_id', $evaluation->participant_id)
                ->first();

            if ($profile === null) {
                return;
            }

            $project = $evaluation->project;
            $organization = $project?->organization;

            ProfessionalRecordItem::updateOrCreate(
                ['idempotency_key' => 'evaluation:'.$evaluation->id],
                [
                    'student_profile_id' => $profile->id,
                    'source_type' => 'evaluation',
                    'source_id' => $evaluation->id,
                    'item_type' => ProfessionalRecordItem::TYPE_EVALUATION,
                    'source_version' => 'rubric-v'.$evaluation->rubric_version,
                    'project_type' => $project?->type,
                    'role' => 'participant',
                    'contribution' => $evaluation->individual_contribution,
                    'issuer' => $organization?->name ?? 'SkillSpan',
                    'supporting_reference' => '/evaluations/'.$evaluation->id,
                    'title' => $project?->title ?? 'Project evaluation',
                    'description' => $evaluation->team_outcome,
                    'verification_state' => ProfessionalRecordItem::VERIFICATION_VERIFIED,
                    'status' => ProfessionalRecordItem::STATUS_ACTIVE,
                    'evaluator_id' => $evaluation->evaluator_id,
                    'visibility' => ProfessionalRecordItem::VISIBILITY_PRIVATE,
                    'achieved_at' => ($evaluation->finalized_at ?? now())->toDateString(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Professional record sync failed.', [
                'evaluation_id' => $evaluation->id,
                'failure_reason' => SafeLog::reason($e),
            ]);
        }
    }
}
