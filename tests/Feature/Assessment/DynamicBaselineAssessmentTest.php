<?php

namespace Tests\Feature\Assessment;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end proof of the dynamic assessment prototype:
 *
 *   specialization -> career roles -> required skills -> baseline questions
 *
 * Runs the real prototype seeders (specializations, skills, career roles,
 * the specialization<->role pivot and the question bank) and drives the
 * actual HTTP endpoints, so it fails if the seeded data and the runtime
 * selection logic ever drift apart.
 *
 * It deliberately seeds only the seeders this feature needs — not the whole
 * DatabaseSeeder — so the test never depends on the university import or
 * any other unrelated data.
 */
class DynamicBaselineAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
        ]);
    }

    public function test_the_eight_prototype_specializations_are_available(): void
    {
        $names = Specialization::query()
            ->where('is_active', true)
            ->pluck('name')
            ->all();

        foreach ([
            'Computer Science',
            'Software Engineering',
            'Information Technology',
            'Information Systems',
            'Data Science',
            'Artificial Intelligence',
            'Cybersecurity',
            'Computer Engineering',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_career_roles_are_connected_to_specializations(): void
    {
        $software = Specialization::where('name', 'Software Engineering')->firstOrFail();

        $titles = $software->careerRoles()->pluck('title')->all();

        // The pivot is the source of truth; the taxonomy grows, so assert
        // membership rather than a frozen snapshot of the whole list.
        $this->assertContains('Backend Developer', $titles);
        $this->assertContains('Frontend Developer', $titles);
        $this->assertContains('Mobile Developer', $titles);
        $this->assertNotEmpty($titles);
    }

    public function test_filtering_by_specialization_returns_only_that_specializations_roles(): void
    {
        $software = Specialization::where('name', 'Software Engineering')->firstOrFail();
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $softwareTitles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)->json('data.data'),
            'title',
        );
        $cyberTitles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$cyber->id)->json('data.data'),
            'title',
        );

        sort($softwareTitles);
        sort($cyberTitles);

        // The endpoint must return exactly the pivot-linked roles, and the
        // two specializations must not bleed into each other.
        $this->assertSame(
            $software->careerRoles()->pluck('title')->sort()->values()->all(),
            $softwareTitles,
        );
        $this->assertSame(
            $cyber->careerRoles()->pluck('title')->sort()->values()->all(),
            $cyberTitles,
        );
        $this->assertNotContains('Security Analyst', $softwareTitles);
        $this->assertNotContains('Backend Developer', $cyberTitles);
    }

    public function test_every_seeded_career_role_produces_a_covered_assessment(): void
    {
        $roles = CareerRole::where('status', 'approved')->get();

        $this->assertNotEmpty($roles, 'The prototype seeder must define approved career roles.');

        foreach ($roles as $role) {
            $user = $this->learnerWithProfile();
            Sanctum::actingAs($user);

            $response = $this->postJson('/api/v1/baseline-assessments', [
                'career_role_id' => $role->id,
            ]);

            $response->assertStatus(201);

            $questions = $response->json('data.questions');

            $this->assertNotEmpty(
                $questions,
                "Career role [{$role->title}] produced no questions — a required skill has no question.",
            );

            // Every selected question must belong to a skill the role
            // actually requires — the mapping is via skills, not chance.
            $requiredSkillIds = $role->roleSkills()->pluck('skill_id')->all();

            foreach ($questions as $question) {
                $this->assertContains(
                    $question['skill_id'],
                    $requiredSkillIds,
                    "Question [{$question['item_id']}] is not mapped to a skill required by [{$role->title}].",
                );
                $this->assertArrayNotHasKey('correct_answer', $question);
            }
        }
    }

    public function test_different_career_roles_produce_different_questions(): void
    {
        $backend = CareerRole::where('slug', 'backend-developer')->firstOrFail();
        $dataScientist = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $backendItems = $this->startAndGetItemIds($backend);
        $dataScienceItems = $this->startAndGetItemIds($dataScientist);

        $this->assertNotEmpty($backendItems);
        $this->assertNotEmpty($dataScienceItems);

        // The two roles share no required skills in the prototype, so the
        // question sets must be disjoint.
        $this->assertSame(
            [],
            array_intersect($backendItems, $dataScienceItems),
            'Backend Developer and Data Scientist must not return the same questions.',
        );
    }

    public function test_software_engineering_mobile_developer_scenario(): void
    {
        $software = Specialization::where('name', 'Software Engineering')->firstOrFail();
        $mobile = CareerRole::where('slug', 'mobile-developer')->firstOrFail();

        $user = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        // Step 1 — the specialization lists the mobile developer role.
        $titles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)->json('data.data'),
            'title',
        );
        $this->assertContains('Mobile Developer', $titles);

        // Step 2 — starting the assessment with the matching specialization
        // succeeds and returns mobile-development questions.
        $response = $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $software->id,
            'career_role_id' => $mobile->id,
        ])->assertStatus(201);

        $questions = $response->json('data.questions');
        $this->assertNotEmpty($questions);

        $requiredSkillIds = $mobile->roleSkills()->pluck('skill_id')->all();

        foreach ($questions as $question) {
            $this->assertContains($question['skill_id'], $requiredSkillIds);
        }
    }

    public function test_cybersecurity_security_analyst_scenario_is_security_specific(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $security = CareerRole::where('slug', 'security-analyst')->firstOrFail();

        $user = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $cyber->id,
            'career_role_id' => $security->id,
        ])->assertStatus(201);

        $returnedSkillIds = array_column($response->json('data.questions'), 'skill_id');
        $securitySkillIds = $security->roleSkills()->pluck('skill_id')->all();

        // Every question belongs to a skill the security analyst requires.
        foreach ($returnedSkillIds as $skillId) {
            $this->assertContains($skillId, $securitySkillIds);
        }

        // ...and none of them is a frontend / mobile skill.
        $frontendSkillIds = CareerRole::where('slug', 'frontend-developer')->firstOrFail()
            ->roleSkills()->pluck('skill_id')->all();
        $mobileSkillIds = CareerRole::where('slug', 'mobile-developer')->firstOrFail()
            ->roleSkills()->pluck('skill_id')->all();

        $this->assertSame([], array_intersect($returnedSkillIds, $frontendSkillIds));
        $this->assertSame([], array_intersect($returnedSkillIds, $mobileSkillIds));
    }

    public function test_mismatched_specialization_and_role_is_rejected_with_seeded_data(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $frontend = CareerRole::where('slug', 'frontend-developer')->firstOrFail();

        $user = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $cyber->id,
            'career_role_id' => $frontend->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    // ------------------------------------------------ helpers

    /**
     * @return array<int, string>
     */
    private function startAndGetItemIds(CareerRole $role): array
    {
        $user = $this->learnerWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
        ])->assertStatus(201);

        return array_column($response->json('data.questions'), 'item_id');
    }

    private function learnerWithProfile(): User
    {
        $user = User::forceCreate([
            'name' => 'Prototype Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }
}
