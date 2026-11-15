<?php

namespace App\Services\Assessment;

use App\Exceptions\QuestionBankException;
use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Skill;
use App\Models\Specialization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class QuestionBankService
{
    public const TYPE_MULTIPLE_CHOICE = 'single_choice';

    public const TYPE_TEXT = 'text';

    public const ANSWER_TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TEXT,
    ];

    public function activeVersion(): string
    {
        return (string) config('services.data_science.baseline.version', 'v1.0');
    }

    public function specializations(): array
    {
        return Specialization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Specialization $s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->all();
    }

    public function careerRoles(int $specializationId): array
    {
        $specialization = Specialization::find($specializationId);

        if (! $specialization) {
            return [];
        }

        $query = CareerRole::query()->selectable();

        $linkedIds = $specialization->isFreeTrack()
            ? []
            : $specialization->careerRoles()
                ->pluck('career_roles.id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if ($linkedIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($linkedIds), '?'));

            $query->orderByRaw(
                "CASE WHEN career_roles.id IN ({$placeholders}) THEN 0 ELSE 1 END ASC",
                $linkedIds,
            );
        }

        return $query
            ->orderBy('career_roles.title')
            ->get(['career_roles.id', 'career_roles.title', 'career_roles.status'])
            ->map(fn ($role) => [
                'id' => (int) $role->id,
                'title' => (string) $role->title,
                'status' => (string) $role->status,
            ])
            ->all();
    }

    public function skills(int $careerRoleId): array
    {
        return CareerRoleSkill::query()
            ->where('career_role_id', $careerRoleId)
            ->with('skill:id,name,slug')
            ->get()
            ->filter(fn (CareerRoleSkill $row) => $row->skill !== null)
            ->map(fn (CareerRoleSkill $row) => [
                'id' => (int) $row->skill->id,
                'name' => (string) $row->skill->name,
                'slug' => (string) $row->skill->slug,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function contextForSkill(int $skillId): array
    {
        $careerRoleId = CareerRoleSkill::query()
            ->where('skill_id', $skillId)
            ->orderBy('career_role_id')
            ->value('career_role_id');

        $specializationId = null;

        if ($careerRoleId !== null) {
            $specializationId = DB::table('career_role_specialization')
                ->where('career_role_id', $careerRoleId)
                ->orderBy('specialization_id')
                ->value('specialization_id');
        }

        return [
            'skill_id' => $skillId,
            'career_role_id' => $careerRoleId !== null ? (int) $careerRoleId : null,
            'specialization_id' => $specializationId !== null ? (int) $specializationId : null,
        ];
    }

    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = BaselineAssessmentItem::query()
            ->where('assessment_version', $this->activeVersion())
            ->with('skill:id,name,slug')
            ->withCount('questionSnapshots as usage_count')
            ->orderByDesc('id');

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where('question_text', 'like', '%'.$search.'%');
        }

        $skillId = $this->intOrNull($filters['skill_id'] ?? null);
        $careerRoleId = $this->intOrNull($filters['career_role_id'] ?? null);
        $specializationId = $this->intOrNull($filters['specialization_id'] ?? null);

        if ($skillId !== null) {
            $query->where('skill_id', $skillId);
        } elseif ($careerRoleId !== null) {
            $query->whereIn('skill_id', $this->skillIdsForCareerRole($careerRoleId));
        } elseif ($specializationId !== null) {
            $specialization = Specialization::find($specializationId);

            if ($specialization !== null && ! $specialization->isFreeTrack()) {
                $query->whereIn('skill_id', $this->skillIdsForSpecialization($specializationId));
            }
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function create(array $data): BaselineAssessmentItem
    {
        $skill = Skill::findOrFail((int) $data['skill_id']);

        return BaselineAssessmentItem::create([
            'assessment_version' => $this->activeVersion(),
            'item_id' => $this->uniqueItemId($skill),
            'item_type' => $data['item_type'],
            'question_text' => $data['question_text'],
            'skill_id' => $skill->id,
            'options' => $this->normalizeOptions($data),
            'correct_answer' => $data['correct_answer'],
            'scoring_rule' => null,
            'weight' => 1.000,
            'is_active' => true,
        ]);
    }

    public function update(BaselineAssessmentItem $item, array $data): BaselineAssessmentItem
    {
        $item->update([
            'item_type' => $data['item_type'],
            'question_text' => $data['question_text'],
            'skill_id' => (int) $data['skill_id'],
            'options' => $this->normalizeOptions($data),
            'correct_answer' => $data['correct_answer'],
        ]);

        return $item->refresh()->load('skill:id,name,slug');
    }

    public function delete(BaselineAssessmentItem $item): void
    {
        $usageCount = $item->questionSnapshots()->count();

        if ($usageCount > 0) {
            throw QuestionBankException::questionInUse((int) $item->id, $usageCount);
        }

        $item->delete();
    }

    private function skillIdsForCareerRole(int $careerRoleId): array
    {
        return CareerRoleSkill::query()
            ->where('career_role_id', $careerRoleId)
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function skillIdsForSpecialization(int $specializationId): array
    {
        $specialization = Specialization::find($specializationId);

        if (! $specialization) {
            return [];
        }

        $roleIds = $specialization->careerRoles()
            ->pluck('career_roles.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($roleIds === []) {
            return [];
        }

        return CareerRoleSkill::query()
            ->whereIn('career_role_id', $roleIds)
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeOptions(array $data): array
    {
        if (($data['item_type'] ?? null) !== self::TYPE_MULTIPLE_CHOICE) {
            return [];
        }

        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        return collect($options)
            ->map(fn ($option) => trim((string) $option))
            ->filter(fn (string $option) => $option !== '')
            ->values()
            ->all();
    }

    private function uniqueItemId(Skill $skill): string
    {
        $version = $this->activeVersion();
        $base = $skill->slug !== '' ? $skill->slug : 'item';

        $existing = BaselineAssessmentItem::query()
            ->where('assessment_version', $version)
            ->where('item_id', 'like', $base.'-%')
            ->pluck('item_id');

        $max = 0;

        foreach ($existing as $itemId) {
            if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', (string) $itemId, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $next = $max + 1;

        do {
            $candidate = $base.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (
            BaselineAssessmentItem::query()
                ->where('assessment_version', $version)
                ->where('item_id', $candidate)
                ->exists()
        );

        return $candidate;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
