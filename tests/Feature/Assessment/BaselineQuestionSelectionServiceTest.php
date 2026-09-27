<?php

namespace Tests\Feature\Assessment;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\Skill;
use App\Services\Baseline\BaselineQuestionSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct unit coverage for the role-based question selection contract:
 * required-skill retrieval, weights/critical flags/prerequisites,
 * variable counts, coverage errors and the total cap.
 */
class BaselineQuestionSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BaselineQuestionSelectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BaselineQuestionSelectionService;

        // Deterministic ordering keeps variable-count assertions exact.
        config(['services.baseline_assessment.deterministic_selection' => true]);
        config(['services.baseline_assessment.min_questions_per_skill' => 1]);
        config(['services.baseline_assessment.max_questions_per_skill' => 3]);
    }

    public function test_throws_when_role_has_no_required_skills(): void
    {
        $role = CareerRole::create([
            'title' => 'Empty Role',
            'slug' => 'empty-role',
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        $this->expectException(BaselineAssessmentException::class);

        try {
            $this->service->select($role, 'v1.0');
        } catch (BaselineAssessmentException $e) {
            $this->assertSame('CAREER_ROLE_NO_SKILLS', $e->codeName);

            throw $e;
        }
    }

    public function test_throws_clear_error_listing_uncovered_skills(): void
    {
        $python = $this->skill('python');
        $sql = $this->skill('sql');

        $role = $this->roleWithSkills([
            [$sql, ['importance_weight' => 0.9, 'is_critical' => true]],
            [$python, ['importance_weight' => 0.5, 'is_critical' => false]],
        ]);

        // Only SQL gets a question; python is uncovered.
        $this->item('sql-001', $sql, 'single_choice', ['A', 'B']);

        try {
            $this->service->select($role, 'v1.0');
            $this->fail('Expected INSUFFICIENT_QUESTION_COVERAGE.');
        } catch (BaselineAssessmentException $e) {
            $this->assertSame('INSUFFICIENT_QUESTION_COVERAGE', $e->codeName);
            $this->assertSame('python', $e->details['uncovered_skills'][0]['slug']);
        }
    }

    public function test_selects_weights_critical_flags_and_prerequisites(): void
    {
        $javascript = $this->skill('javascript');
        $react = $this->skill('react');

        $role = CareerRole::create([
            'title' => 'Frontend Developer',
            'slug' => 'frontend-developer',
            'version' => 2,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        $reactRoleSkill = $role->roleSkills()->create([
            'skill_id' => $react->id,
            'required_level' => 3.5,
            'importance_weight' => 0.9,
            'is_critical' => true,
        ]);
        $reactRoleSkill->prerequisites()->sync([$javascript->id]);

        $role->roleSkills()->create([
            'skill_id' => $javascript->id,
            'required_level' => 3.0,
            'importance_weight' => 0.4,
            'is_critical' => false,
        ]);

        $this->item('javascript-001', $javascript, 'single_choice', ['A', 'B']);
        $this->item('react-001', $react, 'single_choice', ['A', 'B']);

        $selected = $this->service->select($role, 'v1.0');
        $byItemId = $selected->keyBy('item_id');

        $this->assertCount(2, $selected);

        $reactRow = $byItemId['react-001'];
        $this->assertSame(0.9, $reactRow['importance_weight']);
        $this->assertSame(3.5, $reactRow['required_level']);
        $this->assertTrue($reactRow['is_critical']);
        $this->assertSame([$javascript->id], $reactRow['prerequisite_skill_ids']);
        $this->assertSame(['javascript'], $reactRow['prerequisite_slugs']);

        $jsRow = $byItemId['javascript-001'];
        $this->assertFalse($jsRow['is_critical']);
        $this->assertSame([], $jsRow['prerequisite_skill_ids']);
    }

    public function test_variable_count_respects_max_per_skill(): void
    {
        config(['services.baseline_assessment.max_questions_per_skill' => 2]);

        $sql = $this->skill('sql');
        $role = $this->roleWithSkills([[$sql, []]]);

        for ($i = 1; $i <= 5; $i++) {
            $this->item("sql-00{$i}", $sql, 'single_choice', ['A', 'B']);
        }

        $selected = $this->service->select($role, 'v1.0');

        $this->assertCount(2, $selected);
    }

    public function test_single_question_bank_still_yields_one_question(): void
    {
        $sql = $this->skill('sql');
        $role = $this->roleWithSkills([[$sql, []]]);

        $this->item('sql-001', $sql, 'single_choice', ['A', 'B']);

        $this->assertCount(1, $this->service->select($role, 'v1.0'));
    }

    public function test_inactive_items_are_never_selected(): void
    {
        $sql = $this->skill('sql');
        $role = $this->roleWithSkills([[$sql, []]]);

        $active = $this->item('sql-001', $sql, 'single_choice', ['A', 'B']);
        $inactive = $this->item('sql-002', $sql, 'single_choice', ['A', 'B']);
        $inactive->update(['is_active' => false]);

        $selected = $this->service->select($role, 'v1.0');

        $this->assertSame(['sql-001'], $selected->pluck('item_id')->all());
        $this->assertNotContains('sql-002', $selected->pluck('item_id')->all());
        $this->assertNotNull($active->id);
    }

    public function test_total_cap_prefers_critical_skills(): void
    {
        config(['services.baseline_assessment.max_total_questions' => 2]);
        config(['services.baseline_assessment.max_questions_per_skill' => 3]);

        $critical = $this->skill('critical-skill');
        $minor = $this->skill('minor-skill');

        $role = $this->roleWithSkills([
            [$critical, ['is_critical' => true, 'importance_weight' => 0.9]],
            [$minor, ['is_critical' => false, 'importance_weight' => 0.2]],
        ]);

        $this->item('critical-1', $critical, 'single_choice', ['A']);
        $this->item('critical-2', $critical, 'single_choice', ['A']);
        $this->item('minor-1', $minor, 'single_choice', ['A']);

        $selected = $this->service->select($role, 'v1.0');

        $this->assertCount(2, $selected);
        // Both surviving rows are the critical skill's.
        $this->assertSame(
            ['critical-skill'],
            $selected->pluck('skill_slug')->unique()->values()->all()
        );
    }

    private function skill(string $slug): Skill
    {
        return Skill::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function roleWithSkills(array $rows): CareerRole
    {
        $role = CareerRole::create([
            'title' => 'Test Role',
            'slug' => 'test-role-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        foreach ($rows as $index => [$skill, $attributes]) {
            $role->roleSkills()->create(array_merge([
                'skill_id' => $skill->id,
                'required_level' => 3.0,
                'importance_weight' => 0.5,
                'is_critical' => false,
            ], $attributes));
        }

        return $role;
    }

    private function item(string $itemId, Skill $skill, string $type, array $options): BaselineAssessmentItem
    {
        return BaselineAssessmentItem::create([
            'assessment_version' => 'v1.0',
            'item_id' => $itemId,
            'item_type' => $type,
            'skill_id' => $skill->id,
            'options' => $options,
            'correct_answer' => $type === 'single_choice' ? $options[0] : null,
            'weight' => 1.000,
            'is_active' => true,
        ]);
    }
}
