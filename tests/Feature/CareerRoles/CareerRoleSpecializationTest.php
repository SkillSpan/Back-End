<?php

namespace Tests\Feature\CareerRoles;

use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Specialization -> Career Role -> Required Skills -> Baseline Questions.
 *
 * Covers the many-to-many link between specializations and career roles,
 * the `?specialization_id=` filter on the existing career roles endpoint,
 * the optional specialization cross-check on baseline start, and the
 * guarantee that learner-facing assessment payloads never leak grading
 * keys.
 */
class CareerRoleSpecializationTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);
    }

    // ------------------------------------------------ relationships

    public function test_a_specialization_can_have_multiple_career_roles(): void
    {
        $specialization = $this->specialization('Software Engineering');
        $frontend = $this->approvedRole('Frontend Developer');
        $backend = $this->approvedRole('Backend Developer');

        $specialization->careerRoles()->attach([$frontend->id, $backend->id]);

        $titles = $specialization->careerRoles()->pluck('title')->sort()->values()->all();

        $this->assertSame(['Backend Developer', 'Frontend Developer'], $titles);
    }

    public function test_a_career_role_can_belong_to_multiple_specializations(): void
    {
        $backend = $this->approvedRole('Backend Developer');
        $cs = $this->specialization('Computer Science');
        $se = $this->specialization('Software Engineering');

        $backend->specializations()->attach([$cs->id, $se->id]);

        $names = $backend->specializations()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Computer Science', 'Software Engineering'], $names);
    }

    public function test_duplicate_pivot_pair_is_prevented(): void
    {
        $specialization = $this->specialization('Computer Science');
        $role = $this->approvedRole('Backend Developer');

        $specialization->careerRoles()->attach($role->id);

        $this->expectException(UniqueConstraintViolationException::class);

        $specialization->careerRoles()->attach($role->id);
    }

    // ------------------------------------------------ index filter

    public function test_index_without_specialization_filter_preserves_existing_behaviour(): void
    {
        $this->approvedRole('Frontend Developer');
        $this->approvedRole('Backend Developer');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career roles retrieved successfully.');

        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(15, $response->json('data.per_page'));
    }

    public function test_index_with_specialization_filter_returns_only_matching_roles(): void
    {
        $software = $this->specialization('Software Engineering');
        $cyber = $this->specialization('Cybersecurity');

        $frontend = $this->approvedRole('Frontend Developer');
        $backend = $this->approvedRole('Backend Developer');
        $security = $this->approvedRole('Security Analyst');

        $software->careerRoles()->attach([$frontend->id, $backend->id]);
        $cyber->careerRoles()->attach($security->id);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $titles = array_column($response->json('data.data'), 'title');
        sort($titles);

        $this->assertSame(['Backend Developer', 'Frontend Developer'], $titles);
        $this->assertNotContains('Security Analyst', $titles);
    }

    public function test_different_specializations_return_different_roles(): void
    {
        $software = $this->specialization('Software Engineering');
        $cyber = $this->specialization('Cybersecurity');

        $frontend = $this->approvedRole('Frontend Developer');
        $security = $this->approvedRole('Security Analyst');

        $software->careerRoles()->attach($frontend->id);
        $cyber->careerRoles()->attach($security->id);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $softwareTitles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)->json('data.data'),
            'title',
        );
        $cyberTitles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$cyber->id)->json('data.data'),
            'title',
        );

        $this->assertSame(['Frontend Developer'], $softwareTitles);
        $this->assertSame(['Security Analyst'], $cyberTitles);
    }

    public function test_draft_and_retired_roles_are_never_returned_even_when_linked(): void
    {
        $specialization = $this->specialization('Computer Science');

        $approved = $this->approvedRole('Backend Developer');
        $draft = $this->role('Draft Role', 'draft');
        $retired = $this->role('Retired Role', 'retired');

        $specialization->careerRoles()->attach([$approved->id, $draft->id, $retired->id]);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$specialization->id)->json('data.data'),
            'title',
        );

        $this->assertSame(['Backend Developer'], $titles);
    }

    // ------------------------------------------------ validation

    public function test_non_integer_specialization_id_is_rejected(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles?specialization_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    public function test_non_positive_specialization_id_is_rejected(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles?specialization_id=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');

        $this->getJson('/api/v1/career-roles?specialization_id=-3')
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    public function test_unknown_specialization_id_is_rejected(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles?specialization_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    // ------------------------------------------------ authorization

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->getJson('/api/v1/career-roles?specialization_id=1')->assertStatus(401);
    }

    public function test_non_learner_cannot_use_the_specialization_filter(): void
    {
        $specialization = $this->specialization('Computer Science');

        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $this->getJson('/api/v1/career-roles?specialization_id='.$specialization->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    // ------------------------------------------------ baseline cross-check

    public function test_baseline_start_rejects_career_role_from_another_specialization(): void
    {
        $software = $this->specialization('Software Engineering');
        $cyber = $this->specialization('Cybersecurity');

        $frontend = $this->approvedRole('Frontend Developer');
        $security = $this->approvedRole('Security Analyst');

        $software->careerRoles()->attach($frontend->id);
        $cyber->careerRoles()->attach($security->id);

        // Give the security role a valid skill + question so the ONLY thing
        // that can fail the request is the specialization mismatch.
        $this->attachSkillWithQuestion($security, 'Cybersecurity Fundamentals');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $software->id,
            'career_role_id' => $security->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    public function test_baseline_start_accepts_matching_specialization_and_career_role(): void
    {
        $software = $this->specialization('Software Engineering');
        $backend = $this->approvedRole('Backend Developer');
        $software->careerRoles()->attach($backend->id);

        $this->attachSkillWithQuestion($backend, 'REST APIs');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $software->id,
            'career_role_id' => $backend->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.career_role_id', $backend->id);
    }

    public function test_baseline_start_without_specialization_still_works(): void
    {
        $role = $this->approvedRole('Backend Developer');
        $this->attachSkillWithQuestion($role, 'REST APIs');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_baseline_start_rejects_unknown_specialization(): void
    {
        $role = $this->approvedRole('Backend Developer');
        $this->attachSkillWithQuestion($role, 'REST APIs');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => 999999,
            'career_role_id' => $role->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    // ------------------------------------------------ question selection + security

    public function test_baseline_questions_are_selected_from_the_roles_required_skills(): void
    {
        $backend = $this->approvedRole('Backend Developer');
        $backendItem = $this->attachSkillWithQuestion($backend, 'REST APIs');

        // A different role whose skill is NOT part of the backend role.
        $analyst = $this->approvedRole('Data Analyst');
        $analystItem = $this->attachSkillWithQuestion($analyst, 'Data Cleaning');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $backend->id,
        ])->assertStatus(201);

        $itemIds = array_column($response->json('data.questions'), 'item_id');

        $this->assertContains($backendItem->item_id, $itemIds);
        $this->assertNotContains($analystItem->item_id, $itemIds);
    }

    public function test_correct_answers_are_never_exposed_to_the_learner(): void
    {
        $role = $this->approvedRole('Backend Developer');
        $this->attachSkillWithQuestion($role, 'REST APIs');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])->assertStatus(201);

        $payload = $response->getContent();

        $this->assertStringNotContainsString('correct_answer', $payload);
        $this->assertStringNotContainsString('scoring_rule', $payload);

        foreach ($response->json('data.questions') as $question) {
            $this->assertArrayNotHasKey('correct_answer', $question);
            $this->assertArrayNotHasKey('scoring_rule', $question);
        }
    }

    public function test_show_does_not_expose_correct_answers_either(): void
    {
        $role = $this->approvedRole('Backend Developer');
        $this->attachSkillWithQuestion($role, 'REST APIs');

        [$user] = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])->json('data.id');

        $this->getJson('/api/v1/baseline-assessments/'.$assessmentId)
            ->assertOk()
            ->assertJsonStructure(['data' => ['questions' => [['item_id', 'item_type', 'options', 'skill_id']]]]);

        $this->assertStringNotContainsString('correct_answer', $this->getJson('/api/v1/baseline-assessments/'.$assessmentId)->getContent());
    }

    // ------------------------------------------------ helpers

    private function specialization(string $name): Specialization
    {
        return Specialization::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function approvedRole(string $title, int $version = 1): CareerRole
    {
        return $this->role($title, 'approved', $version);
    }

    private function role(string $title, string $status, int $version = 1): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => $version,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }

    /**
     * Attach one required skill to a role and author a matching question,
     * returning the question so the caller can assert on its item_id.
     */
    private function attachSkillWithQuestion(CareerRole $role, string $skillName): BaselineAssessmentItem
    {
        $skill = Skill::create([
            'name' => $skillName,
            'slug' => strtolower(str_replace(' ', '-', $skillName)).'-'.uniqid(),
            'status' => 'active',
        ]);

        CareerRoleSkill::create([
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'required_level' => 3.0,
            'importance_weight' => 0.8,
            'is_critical' => true,
        ]);

        return BaselineAssessmentItem::create([
            'assessment_version' => 'v1.0',
            'item_id' => 'item-'.uniqid(),
            'item_type' => 'single_choice',
            'question_text' => "Prompt for {$skillName}?",
            'skill_id' => $skill->id,
            'options' => ['Option A', 'Option B'],
            'correct_answer' => 'Option A',
            'scoring_rule' => null,
            'weight' => 1.000,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: StudentProfile}
     */
    private function learnerWithProfile(): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);

        return [$user, $profile];
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }
}
