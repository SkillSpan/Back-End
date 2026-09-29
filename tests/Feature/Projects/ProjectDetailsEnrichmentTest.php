<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-MATCH-02 — project-details enrichment.
 *
 * The details endpoint now also reports, for the AUTHENTICATED learner:
 *   - `eligibility`            (from ProjectEligibilityService)
 *   - `capacity_state`         (from ProjectCapacityPolicy)
 *   - `available_project_roles`
 *   - `duration_days`
 *
 * The last two are pure presentation/derivation. The first two are attached
 * ONLY on the details endpoint — the catalog list must not run one eligibility
 * check per project, and the tests below pin that difference so it cannot
 * silently regress into an N+1.
 */
class ProjectDetailsEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createLearner(string $email = 'learner@test.com'): User
    {
        $org = Organization::create(['name' => 'Test Org', 'type' => 'company']);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => $org->id]);
        $user->organizations()->attach($org->id, ['role_in_org' => 'member', 'status' => 'active']);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function createProject(array $attributes = []): Project
    {
        $owner = User::where('email', 'owner@test.com')->value('id')
            ?? User::forceCreate([
                'name' => 'Owner',
                'email' => 'owner@test.com',
                'password' => 'password123',
                'status' => 'active',
                'email_verified_at' => now(),
            ])->id;

        return Project::create(array_merge([
            'organization_id' => Organization::first()->id,
            'owner_id' => $owner,
            'title' => 'Detail Project',
            'description' => 'A project',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'capacity' => 3,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(35)->toDateString(),
            'application_deadline' => now()->addDays(3)->toDateString(),
            'version' => 1,
        ], $attributes));
    }

    public function test_details_include_a_duration_derived_from_the_dates(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.duration_days', 30)
            ->assertJsonPath('data.start_date', now()->addDays(5)->toDateString())
            ->assertJsonPath('data.end_date', now()->addDays(35)->toDateString());
    }

    public function test_duration_is_null_when_the_dates_are_missing(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject(['start_date' => null, 'end_date' => null]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.duration_days', null);
    }

    public function test_details_list_only_the_active_project_roles(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        ProjectRole::create(['project_id' => $project->id, 'title' => 'Backend Developer']);
        ProjectRole::create(['project_id' => $project->id, 'title' => 'Retired Role', 'is_active' => false]);

        $response = $this->getJson("/api/v1/projects/{$project->id}")->assertStatus(200);

        $roles = $response->json('data.available_project_roles');

        $this->assertCount(1, $roles);
        $this->assertSame('Backend Developer', $roles[0]['title']);
    }

    public function test_details_report_eligibility_for_the_authenticated_learner(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.eligibility.eligible', false)
            ->assertJsonPath('data.eligibility.skill_failures.0.skill_id', $skill->id);
    }

    public function test_details_report_an_eligible_learner_as_eligible(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.eligibility.eligible', true);
    }

    public function test_details_report_the_capacity_state(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 2]);

        $other = $this->createLearner('other@test.com');
        $accepted = new Application(['project_id' => $project->id, 'applicant_id' => $other->id]);
        $accepted->status = Application::STATUS_ACCEPTED;
        $accepted->save();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.capacity_state.capacity', 2)
            ->assertJsonPath('data.capacity_state.seats_taken', 1)
            ->assertJsonPath('data.capacity_state.seats_remaining', 1)
            ->assertJsonPath('data.capacity_state.full', false);
    }

    public function test_details_report_a_full_project_as_full(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 1]);

        $other = $this->createLearner('other@test.com');
        $accepted = new Application(['project_id' => $project->id, 'applicant_id' => $other->id]);
        $accepted->status = Application::STATUS_ACCEPTED;
        $accepted->save();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.capacity_state.full', true)
            ->assertJsonPath('data.capacity_state.seats_remaining', 0);
    }

    public function test_the_catalog_list_does_not_compute_per_learner_eligibility(): void
    {
        // Pins the deliberate difference: `eligibility` and `capacity_state` are
        // attached only on the details endpoint, so a 50-project catalog page
        // does not run 50 eligibility checks. An absent block must never be read
        // as "eligible" — it is null, which is explicitly not a positive answer.
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->createProject();

        $response = $this->getJson('/api/v1/projects')->assertStatus(200);

        $this->assertNull($response->json('data.0.eligibility'));
        $this->assertNull($response->json('data.0.capacity_state'));
    }

    public function test_the_details_response_keeps_the_existing_field_contract(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'title', 'description', 'type', 'domain', 'objectives',
                    'learning_outcomes', 'difficulty', 'work_mode', 'role', 'schedule',
                    'capacity', 'min_team_size', 'application_deadline', 'start_date',
                    'end_date', 'status', 'confidentiality', 'version', 'organization',
                    'required_skills',
                ],
                'request_id',
            ]);
    }
}
