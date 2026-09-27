<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task C — question content validation.
 *
 * A selected question must be usable: a non-empty authored prompt, plus
 * options that satisfy its declared item type. Invalid content is a
 * hard, explicit failure — the assessment is refused rather than
 * shipping a blank question or inventing a placeholder prompt.
 */
class QuestionContentValidationTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create([
            'name' => 'Learner', 'slug' => 'learner', 'description' => '',
        ]);

        Config::set('services.data_science.baseline.enabled', true);
        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);
        Config::set('services.data_science.service_token', 'test-service-token');
    }

    public function test_question_with_empty_text_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $this->makeItem('sql-001', $sql->id, 'single_choice', ['A', 'B'], '   ');

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT')
            ->assertJsonPath('details.invalid_questions.0.item_id', 'sql-001')
            ->assertJsonPath('details.invalid_questions.0.problems', ['missing_question_text']);
    }

    public function test_question_with_active_but_unusable_content_is_never_silently_skipped(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql', 'python']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $python = Skill::firstOrCreate(['slug' => 'python'], ['name' => 'Python', 'status' => 'active']);

        $this->makeItem('sql-001', $sql->id, 'single_choice', ['A', 'B'], 'Valid SQL prompt?');
        // The only Python question is broken — the assessment must not
        // quietly proceed with SQL alone.
        $this->makeItem('python-001', $python->id, 'single_choice', ['A', 'B'], null);

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT');
    }

    public function test_single_choice_without_options_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $this->makeItem('sql-001', $sql->id, 'single_choice', [], 'Prompt with no options?');

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT')
            ->assertJsonPath('details.invalid_questions.0.problems', ['insufficient_distinct_options']);
    }

    public function test_single_choice_with_only_one_distinct_option_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        // Duplicates collapse to a single distinct option — not a real
        // choice, so the answer could never be validated meaningfully.
        $this->makeItem('sql-001', $sql->id, 'single_choice', ['A', 'A'], 'Only one real option?');

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT')
            ->assertJsonPath('details.invalid_questions.0.problems', ['insufficient_distinct_options']);
    }

    public function test_scale_question_requires_anchors(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $this->makeItem('sql-001', $sql->id, 'scale', [], 'Rate your SQL confidence.');

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT')
            ->assertJsonPath('details.invalid_questions.0.problems', ['missing_options']);
    }

    public function test_valid_scale_question_is_accepted(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $this->makeItem('sql-scale-1', $sql->id, 'scale', ['1', '2', '3', '4', '5'], 'Rate your SQL confidence.');

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.questions.0.item_type', 'scale')
            ->assertJsonPath('data.questions.0.question_text', 'Rate your SQL confidence.');
    }

    public function test_all_content_problems_are_reported_together(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);

        // Missing text AND insufficient options on the same question.
        $this->makeItem('sql-001', $sql->id, 'single_choice', ['A'], null);

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QUESTION_CONTENT');

        $problems = $response->json('details.invalid_questions.0.problems');

        $this->assertContains('missing_question_text', $problems);
        $this->assertContains('insufficient_distinct_options', $problems);
    }

    public function test_valid_question_text_is_returned_and_frozen_in_the_snapshot(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql']);
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $this->makeItem('sql-001', $sql->id, 'single_choice', ['A', 'B'], 'Which clause filters rows?');

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $assessmentId = $response->json('data.id');
        $this->assertSame('Which clause filters rows?', $response->json('data.questions.0.question_text'));

        // Frozen into the snapshot, so the content survives bank edits.
        $this->assertDatabaseHas('baseline_question_snapshots', [
            'baseline_assessment_id' => $assessmentId,
            'item_id' => 'sql-001',
        ]);

        BaselineAssessmentItem::query()->where('item_id', 'sql-001')->update([
            'question_text' => 'Rewritten after the fact.',
        ]);

        $this->getJson("/api/v1/baseline-assessments/{$assessmentId}")
            ->assertStatus(200)
            ->assertJsonPath('data.questions.0.question_text', 'Which clause filters rows?');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string>  $skillSlugs
     */
    private function role(array $skillSlugs): CareerRole
    {
        $role = CareerRole::create([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        foreach ($skillSlugs as $index => $slug) {
            $skill = Skill::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst($slug), 'status' => 'active'],
            );

            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3.0,
                'importance_weight' => round(0.9 - ($index * 0.1), 3),
                'is_critical' => $index === 0,
            ]);
        }

        return $role;
    }

    /**
     * @param  array<int, string>  $options
     */
    private function makeItem(
        string $itemId,
        int $skillId,
        string $type,
        array $options,
        ?string $questionText
    ): BaselineAssessmentItem {
        return BaselineAssessmentItem::updateOrCreate(
            ['assessment_version' => 'v1.0', 'item_id' => $itemId],
            [
                'item_type' => $type,
                'question_text' => $questionText,
                'skill_id' => $skillId,
                'options' => $options,
                'correct_answer' => $type === 'single_choice' ? ($options[0] ?? null) : null,
                'scoring_rule' => null,
                'weight' => 1.000,
                'is_active' => true,
            ],
        );
    }

    private function createLearner(): array
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
