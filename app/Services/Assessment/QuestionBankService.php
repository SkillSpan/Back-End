<?php

namespace App\Services\Assessment;

use App\Exceptions\QuestionBankException;
use App\Models\BaselineAssessmentItem;
use App\Models\CareerRoleSkill;
use App\Models\Skill;
use App\Models\Specialization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Question-bank management for the admin "Questions" page.
 *
 * WHY THIS EXISTS
 * ---------------
 * The page drives the Dynamic Assessment chain
 * (specialization -> career role -> skill -> question) and must not
 * re-implement any of it: the relationships already live on the models
 * (Specialization::careerRoles(), CareerRole::roleSkills(), Skill) and the
 * questions are ordinary `baseline_assessment_items` rows. This service is
 * the one place that reads and writes those rows for the panel, so the
 * controller stays a thin HTTP adapter and the same rules can be tested
 * without a browser.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It never invents a second question store: every method works on
 * `baseline_assessment_items`, keyed to a `skill_id`, exactly the shape
 * BaselineQuestionSelectionService already selects from. New questions are
 * authored against the ACTIVE assessment version, so they are immediately
 * eligible for the baseline flow.
 */
class QuestionBankService
{
    public const TYPE_MULTIPLE_CHOICE = 'single_choice';

    public const TYPE_TEXT = 'text';

    /** The answer shapes the panel may author. */
    public const ANSWER_TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TEXT,
    ];

    /**
     * The assessment version new questions are authored against — the same
     * value BaselineAssessmentService freezes an assessment to, so a
     * question created here is picked up by the next assessment.
     */
    public function activeVersion(): string
    {
        return (string) config('services.data_science.baseline.version', 'v1.0');
    }

    /**
     * Active specializations for the first dropdown.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public function specializations(): array
    {
        return Specialization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Specialization $s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->all();
    }

    /**
     * Career roles reachable from a specialization, for the second
     * dropdown. All statuses are returned so an operator can see the full
     * mapping; the assessment flow still only uses approved roles.
     *
     * The "Self-Learning / Free Track" specialization returns EVERY role,
     * because any role is valid under it.
     *
     * @return array<int, array{id:int,title:string,status:string}>
     */
    public function careerRoles(int $specializationId): array
    {
        $specialization = Specialization::find($specializationId);

        if (! $specialization) {
            return [];
        }

        return $specialization->availableCareerRolesQuery()
            ->orderBy('title')
            ->get(['career_roles.id', 'career_roles.title', 'career_roles.status'])
            ->map(fn ($role) => [
                'id' => (int) $role->id,
                'title' => (string) $role->title,
                'status' => (string) $role->status,
            ])
            ->all();
    }

    /**
     * Required skills of a career role (the third dropdown). Ordered by name
     * so the select is stable.
     *
     * @return array<int, array{id:int,name:string,slug:string}>
     */
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

    /**
     * Resolve the specialization / career role a skill sits under, so the
     * edit form can pre-select the chain for a question it did not filter
     * down to. A skill can be required by several roles; the first one (by
     * id) is returned, which is deterministic.
     *
     * @return array{skill_id:int,career_role_id:?int,specialization_id:?int}
     */
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

    /**
     * Paginated questions for the current filters, scoped to the active
     * version. Narrowing is applied at the finest available level: a skill
     * filter wins over a career role, which wins over a specialization.
     *
     * @param  array{specialization_id?:mixed,career_role_id?:mixed,skill_id?:mixed,q?:mixed}  $filters
     */
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
            // The free track exposes every role, so every skill is in scope
            // and no restriction is applied.
            $specialization = Specialization::find($specializationId);

            if ($specialization !== null && ! $specialization->isFreeTrack()) {
                $query->whereIn('skill_id', $this->skillIdsForSpecialization($specializationId));
            }
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Create a question in the existing item bank.
     *
     * The `item_id` is generated (never taken from the client) and the
     * assessment version is the active one, so a question can never be
     * created against an arbitrary or inactive version.
     *
     * @param  array<string, mixed>  $data
     */
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

    /**
     * Update a question. `item_id` and `assessment_version` are immutable:
     * a frozen snapshot references the item by id, and changing the version
     * would silently move the question out of the bank the panel is showing.
     *
     * @param  array<string, mixed>  $data
     */
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

    /**
     * Delete a question that is not yet part of any assessment.
     *
     * The snapshot FK cascades, so an item already frozen into an
     * assessment must never be deleted here — that is refused with a 409
     * rather than allowed to erase the learner's question set.
     */
    public function delete(BaselineAssessmentItem $item): void
    {
        $usageCount = $item->questionSnapshots()->count();

        if ($usageCount > 0) {
            throw QuestionBankException::questionInUse((int) $item->id, $usageCount);
        }

        $item->delete();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return array<int, int>
     */
    private function skillIdsForCareerRole(int $careerRoleId): array
    {
        return CareerRoleSkill::query()
            ->where('career_role_id', $careerRoleId)
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
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

    /**
     * The stored options: trimmed, non-empty, re-indexed. A free-text
     * question stores an empty array — the `options` column is NOT NULL.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
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

    /**
     * A stable, unique, human-readable item id in the existing
     * "{skillSlug}-NNN" style, unique within the active version.
     */
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
