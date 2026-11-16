<?php

namespace App\Services\Evaluation;

use App\Exceptions\EvaluationException;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\SelfReflection;
use App\Models\User;
use App\Services\Projects\ProjectWorkspaceAccess;
use App\Traits\AuditsActions;

class SelfReflectionService
{
    use AuditsActions;

    public function __construct(private readonly ProjectWorkspaceAccess $access) {}

    public function forProject(Project $project, User $user): ?SelfReflection
    {
        $this->access->assertMember($project, $user);

        return SelfReflection::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function save(
        Project $project,
        User $user,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SelfReflection {
        $this->access->assertMember($project, $user);

        $reflection = SelfReflection::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if ($reflection !== null && $reflection->submitted_at !== null) {
            throw new EvaluationException(
                'This self-reflection has already been submitted and can no longer be edited.',
                422,
                'REFLECTION_IMMUTABLE',
                ['project_id' => $project->id],
            );
        }

        $attributes = [
            'answers' => $data['answers'] ?? null,
            'learning_summary' => $data['learning_summary'] ?? null,
        ];

        if ($reflection === null) {
            $reflection = SelfReflection::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                ...$attributes,
            ]);

            $action = 'reflection.created';
        } else {
            $reflection->forceFill($attributes)->save();

            $action = 'reflection.updated';
        }

        $this->audit(
            $user->id,
            $action,
            'project',
            $project->id,
            null,
            ['reflection_id' => $reflection->id],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $reflection->fresh();
    }

    public function submit(
        Project $project,
        User $user,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SelfReflection {
        $this->access->assertMember($project, $user);

        $reflection = SelfReflection::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if ($reflection === null) {
            throw new EvaluationException(
                'Write your self-reflection before submitting it.',
                422,
                'REFLECTION_MISSING',
                ['project_id' => $project->id],
            );
        }

        if ($reflection->submitted_at !== null) {
            return $reflection;
        }

        $this->assertFeedbackAvailable($project, $user);

        if (trim((string) $reflection->learning_summary) === '' && empty($reflection->answers)) {
            throw new EvaluationException(
                'A self-reflection needs at least one answer or a learning summary.',
                422,
                'REFLECTION_EMPTY',
                ['project_id' => $project->id],
            );
        }

        $reflection->forceFill(['submitted_at' => now()])->save();

        $this->audit(
            $user->id,
            'reflection.submitted',
            'project',
            $project->id,
            null,
            ['reflection_id' => $reflection->id],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $reflection->fresh();
    }

    public function payload(SelfReflection $reflection): array
    {
        return [
            'id' => $reflection->id,
            'project_id' => $reflection->project_id,
            'answers' => $reflection->answers ?? [],
            'learning_summary' => $reflection->learning_summary,
            'submitted_at' => $reflection->submitted_at?->toIso8601String(),
            'is_submitted' => $reflection->submitted_at !== null,
            'updated_at' => $reflection->updated_at?->toIso8601String(),
        ];
    }

    private function assertFeedbackAvailable(Project $project, User $user): void
    {
        $finalized = Evaluation::query()
            ->where('project_id', $project->id)
            ->where('participant_id', $user->id)
            ->whereIn('status', Evaluation::STUDENT_VISIBLE_STATUSES)
            ->exists();

        if ($finalized) {
            return;
        }

        throw new EvaluationException(
            'A self-reflection can be submitted once your project feedback is finalized.',
            422,
            'REFLECTION_FEEDBACK_PENDING',
            ['project_id' => $project->id],
        );
    }
}
