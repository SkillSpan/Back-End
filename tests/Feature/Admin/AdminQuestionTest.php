<?php

namespace Tests\Feature\Admin;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The admin "Questions" page and its session-authenticated endpoints.
 *
 * Covers the Dynamic Assessment chain the page manages
 * (specialization -> career role -> skill -> question): the dependent
 * dropdowns, the list filter, create / edit / delete, the server-side
 * cross-checks, and the guarantee that the learner-facing assessment still
 * never sees a grading key.
 */
class AdminQuestionTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);
    }

    // ------------------------------------------------ authorization

    public function test_a_guest_cannot_open_the_questions_page(): void
    {
        $this->get('/admin/questions')->assertRedirect(route('login'));
    }

    public function test_a_learner_cannot_open_the_questions_page(): void
    {
        $this->actingAs($this->learner())
            ->get('/admin/questions')
            ->assertStatus(403);
    }

    public function test_a_learner_cannot_call_the_questions_api(): void
    {
        $this->actingAs($this->learner())
            ->getJson('/admin/api/questions')
            ->assertStatus(403);
    }

    public function test_an_admin_can_open_the_questions_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/questions')
            ->assertOk()
            ->assertSee('Questions');
    }

    // ------------------------------------------------ dynamic dropdowns

    public function test_specializations_endpoint_returns_active_specializations(): void
    {
        $this->actingAs($this->admin());

        $this->specialization('Software Engineering');
        $this->specialization('Cybersecurity');

        $response = $this->getJson('/admin/api/questions/specializations')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Software Engineering', $names);
        $this->assertContains('Cybersecurity', $names);
    }

    public function test_career_roles_endpoint_returns_only_the_specializations_roles(): void
    {
        $this->actingAs($this->admin());

        $software = $this->specialization('Software Engineering');
        $cyber = $this->specialization('Cybersecurity');

        $frontend = $this->careerRole('Frontend Developer');
        $security = $this->careerRole('Security Analyst');

        $software->careerRoles()->attach($frontend->id);
        $cyber->careerRoles()->attach($security->id);

        $titles = array_column(
            $this->getJson('/admin/api/questions/career-roles?specialization_id='.$software->id)->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['Frontend Developer'], $titles);
    }

    public function test_skills_endpoint_returns_only_the_roles_skills(): void
    {
        $this->actingAs($this->admin());

        $role = $this->careerRole('Backend Developer');
        $rest = $this->skill('REST APIs', 'rest-apis');
        $sql = $this->skill('SQL & Databases', 'sql-databases');
        $unrelated = $this->skill('Nursing', 'nursing');

        $this->requireSkill($role, $rest);
        $this->requireSkill($role, $sql);

        $names = array_column(
            $this->getJson('/admin/api/questions/skills?career_role_id='.$role->id)->assertOk()->json('data'),
            'name',
        );

        sort($names);

        $this->assertSame(['REST APIs', 'SQL & Databases'], $names);
        $this->assertNotContains('Nursing', $names);
        $this->assertNotNull($unrelated->id);
    }

    public function test_career_roles_endpoint_requires_a_specialization(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/admin/api/questions/career-roles')
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    // ------------------------------------------------ listing

    public function test_questions_endpoint_filters_by_skill(): void
    {
        $this->actingAs($this->admin());

        $rest = $this->skill('REST APIs', 'rest-apis');
        $sql = $this->skill('SQL & Databases', 'sql-databases');

        $restQuestion = $this->makeQuestion($rest, ['item_id' => 'rest-apis-001', 'question_text' => 'What is a REST API?']);
        $this->makeQuestion($sql, ['item_id' => 'sql-databases-001', 'question_text' => 'What is a primary key?']);

        $response = $this->getJson('/admin/api/questions?skill_id='.$rest->id)->assertOk();

        $itemIds = array_column($response->json('data.data'), 'item_id');

        $this->assertSame([$restQuestion->item_id], $itemIds);
        $this->assertSame(1, $response->json('data.total'));
    }

    // ------------------------------------------------ create

    public function test_an_admin_can_create_a_multiple_choice_question(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();

        $response = $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'single_choice',
            'question_text' => 'What is the main difference between GET and POST?',
            'options' => ['GET retrieves data, POST submits data', 'They are identical', 'POST is only for files'],
            'correct_answer' => 'GET retrieves data, POST submits data',
        ])->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.item_type', 'single_choice')
            ->assertJsonPath('data.skill_id', $skill->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.correct_answer', 'GET retrieves data, POST submits data');

        // The item id is generated from the skill slug, in the bank's style.
        $this->assertSame('rest-apis-001', $response->json('data.item_id'));

        $this->assertDatabaseHas('baseline_assessment_items', [
            'item_id' => 'rest-apis-001',
            'skill_id' => $skill->id,
            'assessment_version' => 'v1.0',
        ]);
    }

    public function test_an_admin_can_create_a_text_answer_question(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();

        $response = $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'text',
            'question_text' => 'Explain what a REST API is.',
            'correct_answer' => 'An interface that lets systems exchange data over HTTP.',
        ])->assertStatus(201)
            ->assertJsonPath('data.item_type', 'text')
            ->assertJsonPath('data.correct_answer', 'An interface that lets systems exchange data over HTTP.');

        // A text question carries no options.
        $this->assertSame([], $response->json('data.options'));
    }

    // ------------------------------------------------ validation

    public function test_creating_a_question_requires_the_whole_chain(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/admin/api/questions', [
            'item_type' => 'single_choice',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'specialization_id',
                'career_role_id',
                'skill_id',
                'question_text',
                'correct_answer',
            ]);
    }

    public function test_a_career_role_from_another_specialization_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $software = $this->specialization('Software Engineering');
        $cyber = $this->specialization('Cybersecurity');

        $frontend = $this->careerRole('Frontend Developer');
        $software->careerRoles()->attach($frontend->id);

        $skill = $this->skill('HTML', 'html');
        $this->requireSkill($frontend, $skill);

        // Cybersecurity does not contain Frontend Developer.
        $this->postJson('/admin/api/questions', [
            'specialization_id' => $cyber->id,
            'career_role_id' => $frontend->id,
            'skill_id' => $skill->id,
            'item_type' => 'single_choice',
            'question_text' => 'What is HTML?',
            'options' => ['A markup language', 'A database'],
            'correct_answer' => 'A markup language',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    public function test_a_skill_not_required_by_the_role_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $specialization = $this->specialization('Software Engineering');
        $role = $this->careerRole('Backend Developer');
        $specialization->careerRoles()->attach($role->id);

        $required = $this->skill('REST APIs', 'rest-apis');
        $other = $this->skill('Nursing', 'nursing');
        $this->requireSkill($role, $required);

        $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $other->id,
            'item_type' => 'single_choice',
            'question_text' => 'A question for the wrong skill?',
            'options' => ['A', 'B'],
            'correct_answer' => 'A',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('skill_id');
    }

    public function test_a_multiple_choice_question_needs_at_least_two_options(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();

        $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'single_choice',
            'question_text' => 'Only one option?',
            'options' => ['Only one'],
            'correct_answer' => 'Only one',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_the_correct_answer_must_be_one_of_the_options(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();

        $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'single_choice',
            'question_text' => 'Correct answer outside the options?',
            'options' => ['A', 'B'],
            'correct_answer' => 'C',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correct_answer');
    }

    // ------------------------------------------------ update / delete

    public function test_an_admin_can_update_a_question(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();
        $question = $this->makeQuestion($skill, ['item_id' => 'rest-apis-001', 'question_text' => 'Old text?']);

        $this->patchJson('/admin/api/questions/'.$question->id, [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'single_choice',
            'question_text' => 'New text?',
            'options' => ['One', 'Two'],
            'correct_answer' => 'Two',
        ])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'New text?')
            ->assertJsonPath('data.correct_answer', 'Two');

        $this->assertDatabaseHas('baseline_assessment_items', [
            'id' => $question->id,
            'question_text' => 'New text?',
            'correct_answer' => 'Two',
        ]);
    }

    public function test_an_admin_can_delete_an_unused_question(): void
    {
        $this->actingAs($this->admin());

        $skill = $this->skill('REST APIs', 'rest-apis');
        $question = $this->makeQuestion($skill, ['item_id' => 'rest-apis-001']);

        $this->deleteJson('/admin/api/questions/'.$question->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('baseline_assessment_items', ['id' => $question->id]);
    }

    public function test_a_question_used_by_an_assessment_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $role = $this->careerRole('Backend Developer');
        $skill = $this->skill('REST APIs', 'rest-apis');
        $this->requireSkill($role, $skill);
        $question = $this->makeQuestion($skill, ['item_id' => 'rest-apis-001']);

        // Simulate a question already frozen into an assessment.
        $profile = StudentProfile::forceCreate(['user_id' => $this->learner()->id]);
        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
        ]);
        BaselineQuestionSnapshot::forceCreate([
            'baseline_assessment_id' => $assessment->id,
            'baseline_assessment_item_id' => $question->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'importance_weight' => 0.8,
            'is_critical' => true,
        ]);

        $this->deleteJson('/admin/api/questions/'.$question->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'QUESTION_IN_USE');

        $this->assertDatabaseHas('baseline_assessment_items', ['id' => $question->id]);
    }

    public function test_skill_context_resolves_the_chain_for_editing(): void
    {
        $this->actingAs($this->admin());

        [$specialization, $role, $skill] = $this->chain();

        $this->getJson('/admin/api/questions/skill-context?skill_id='.$skill->id)
            ->assertOk()
            ->assertJsonPath('data.skill_id', $skill->id)
            ->assertJsonPath('data.career_role_id', $role->id)
            ->assertJsonPath('data.specialization_id', $specialization->id);
    }

    // ------------------------------------------------ learner-facing safety

    public function test_the_admin_endpoint_exposes_the_answer_but_the_learner_api_does_not(): void
    {
        $admin = $this->admin();
        $learner = $this->learner();
        StudentProfile::forceCreate(['user_id' => $learner->id]);

        [$specialization, $role, $skill] = $this->chain();
        $this->makeQuestion($skill, [
            'item_id' => 'rest-apis-001',
            'question_text' => 'What is a REST API?',
            'options' => ['An interface', 'A database'],
            'correct_answer' => 'An interface',
        ]);

        // Admin sees the grading key...
        $this->actingAs($admin);
        $adminBody = $this->getJson('/admin/api/questions?skill_id='.$skill->id)->assertOk();
        $this->assertSame('An interface', $adminBody->json('data.data.0.correct_answer'));

        // ...the learner does not.
        $this->actingAs($learner);
        $learnerBody = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])->assertStatus(201);

        $this->assertStringNotContainsString('correct_answer', $learnerBody->getContent());
        $this->assertNotEmpty($learnerBody->json('data.questions'));
    }

    public function test_a_created_text_question_is_selectable_by_the_baseline_flow(): void
    {
        $admin = $this->admin();
        $learner = $this->learner();
        StudentProfile::forceCreate(['user_id' => $learner->id]);

        [$specialization, $role, $skill] = $this->chain();

        $this->actingAs($admin);
        $this->postJson('/admin/api/questions', [
            'specialization_id' => $specialization->id,
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'item_type' => 'text',
            'question_text' => 'Explain what a REST API is.',
            'correct_answer' => 'An interface that lets systems exchange data.',
        ])->assertStatus(201);

        // The text question must not break coverage or content validation.
        $this->actingAs($learner);
        $response = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])->assertStatus(201);

        $itemIds = array_column($response->json('data.questions'), 'item_id');

        $this->assertContains('rest-apis-001', $itemIds);
    }

    // ------------------------------------------------ helpers

    /**
     * @return array{0: Specialization, 1: CareerRole, 2: Skill}
     */
    private function chain(): array
    {
        $specialization = $this->specialization('Software Engineering');
        $role = $this->careerRole('Backend Developer');
        $specialization->careerRoles()->attach($role->id);

        $skill = $this->skill('REST APIs', 'rest-apis');
        $this->requireSkill($role, $skill);

        return [$specialization, $role, $skill];
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => uniqid().'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->adminRole->id);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'learner@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function specialization(string $name): Specialization
    {
        return Specialization::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function careerRole(string $title, string $status = 'approved'): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function skill(string $name, string $slug): Skill
    {
        return Skill::firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active']);
    }

    private function requireSkill(CareerRole $role, Skill $skill): CareerRoleSkill
    {
        return CareerRoleSkill::create([
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'required_level' => 3.0,
            'importance_weight' => 0.8,
            'is_critical' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeQuestion(Skill $skill, array $attributes = []): BaselineAssessmentItem
    {
        return BaselineAssessmentItem::create(array_merge([
            'assessment_version' => 'v1.0',
            'item_id' => 'item-'.uniqid(),
            'item_type' => 'single_choice',
            'question_text' => 'A baseline question?',
            'skill_id' => $skill->id,
            'options' => ['A', 'B'],
            'correct_answer' => 'A',
            'scoring_rule' => null,
            'weight' => 1.000,
            'is_active' => true,
        ], $attributes));
    }
}
