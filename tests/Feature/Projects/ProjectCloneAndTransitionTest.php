<?php

namespace Tests\Feature\Projects;

use App\Models\CareerRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectCloneAndTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
        Role::create(['name' => 'University Admin', 'slug' => 'university_admin', 'description' => '']);
    }

    private function organization(string $name = 'Test Org'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'verified',
        ]);
    }

    private function user(string $email, string $role, ?Organization $organization = null): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        if ($organization !== null) {
            $user->organizations()->attach($organization->id, [
                'role_in_org' => 'admin',
                'status' => 'active',
            ]);
        }

        return $user->fresh();
    }

    private function skill(): Skill
    {
        return Skill::first() ?? Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
    }

    private function careerRole(): CareerRole
    {
        $role = CareerRole::first() ?? CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer',
            'version' => 1,
            'status' => 'approved',
        ]);

        $skill = $this->skill();

        if (! $role->roleSkills()->where('skill_id', $skill->id)->exists()) {
            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3,
                'importance_weight' => 1,
                'is_critical' => true,
            ]);
        }

        return $role;
    }

    private function completeProject(string $status = Project::STATUS_DRAFT, ?User $owner = null): Project
    {
        $owner ??= $this->user('owner@test.com', 'company_admin', $this->organization());

        $project = Project::create([
            'organization_id' => $owner->organizations()->first()?->id,
            'owner_id' => $owner->id,
            'title' => 'Source Project',
            'description' => 'Already described.',
            'objectives' => 'Already scoped.',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => 3,
            'work_mode' => 'remote',
            'difficulty' => 3,
            'career_role_id' => $this->careerRole()->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $project->requiredSkills()->create([
            'skill_id' => $this->skill()->id,
            'minimum_level' => 3,
            'is_critical_entry' => true,
        ]);

        $project->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);
        $project->projectRoles()->create(['title' => 'Retired Role', 'is_active' => false]);
        $project->eligibilityConstraints()->create(['constraint_type' => 'location', 'value' => 'Damascus']);

        return $project->fresh();
    }

    public function test_an_owner_can_clone_a_project_into_a_new_draft(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $source = $this->completeProject(Project::STATUS_OPEN, $owner);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$source->id}/clone")
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Project::STATUS_DRAFT)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.title', 'Source Project (copy)');

        $this->assertNotSame($source->id, $response->json('data.id'));

        $this->assertSame(Project::STATUS_OPEN, $source->fresh()->status);
    }

    public function test_a_clone_copies_the_child_rows_that_make_it_submittable(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/v1/projects/{$source->id}/clone")
            ->assertStatus(201)
            ->json('data.id');

        $copy = Project::find($id);

        $this->assertSame(1, $copy->requiredSkills()->count());
        $this->assertSame(2, $copy->projectRoles()->count());
        $this->assertSame(1, $copy->eligibilityConstraints()->count());

        $this->assertSame(1, $copy->projectRoles()->where('is_active', false)->count());

        $this->assertSame($source->owner_id, $copy->owner_id);
        $this->assertSame($source->organization_id, $copy->organization_id);
    }

    public function test_the_clone_accepts_a_new_title_and_new_dates(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        $start = now()->addDays(90)->toDateString();
        $end = now()->addDays(120)->toDateString();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$source->id}/clone", [
            'title' => 'Spring Cohort',
            'start_date' => $start,
            'end_date' => $end,
            'capacity' => 6,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Spring Cohort')
            ->assertJsonPath('data.start_date', $start)
            ->assertJsonPath('data.end_date', $end)
            ->assertJsonPath('data.capacity', 6);
    }

    public function test_a_long_source_title_is_truncated_so_the_copy_still_saves(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        $source->forceFill(['title' => str_repeat('A', 250)])->save();

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$source->id}/clone")->assertStatus(201);

        $this->assertLessThanOrEqual(255, mb_strlen($response->json('data.title')));
    }

    public function test_a_non_owner_cannot_clone_a_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $stranger = $this->user('stranger@test.com', 'company_admin', $this->organization('Globex'));

        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/projects/{$source->id}/clone")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    public function test_a_platform_administrator_can_clone_any_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $admin = $this->user('admin@test.com', 'admin');

        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/projects/{$source->id}/clone")->assertStatus(201);

        $copy = Project::find($response->json('data.id'));
        $this->assertSame($source->organization_id, $copy->organization_id);
        $this->assertSame($source->owner_id, $copy->owner_id);
    }

    public function test_cloning_an_unknown_project_answers_404(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/projects/999999/clone')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_an_invalid_capacity_is_a_validation_error(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $source = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$source->id}/clone", ['capacity' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_dispatcher_submits_a_draft(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'submitted'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SUBMITTED)
            ->assertJsonPath('message', 'Project submitted for review.');

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_the_dispatcher_enforces_the_same_authorization_as_the_dedicated_route(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_SUBMITTED, $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'approved'])
            ->assertStatus(403);

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_the_dispatcher_refuses_a_target_the_current_state_cannot_reach(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'open'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(['submitted'], $response->json('details.allowed_transitions'));
    }

    public function test_the_dispatcher_reports_an_empty_executable_set_for_a_dead_end_state(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_ARCHIVED, $owner);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame([], $response->json('details.allowed_transitions'));
    }

    public function test_the_dispatcher_rejects_a_status_that_does_not_exist(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_DRAFT, $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'nonsense'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_dispatcher_requires_a_reason_when_requesting_changes(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $admin = $this->user('admin@test.com', 'admin');
        $project = $this->completeProject(Project::STATUS_SUBMITTED, $owner);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'changes_requested'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_REASON_REQUIRED');

        $this->postJson("/api/v1/projects/{$project->id}/transitions", [
            'to' => 'changes_requested',
            'reason' => 'The scope is too broad for one term.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_CHANGES_REQUESTED);

        $this->assertSame(Project::STATUS_CHANGES_REQUESTED, $project->fresh()->status);
    }

    public function test_the_dispatcher_opens_an_approved_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->completeProject(Project::STATUS_APPROVED, $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_OPEN);
    }

    public function test_the_dispatcher_answers_404_for_an_unknown_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/projects/999999/transitions', ['to' => 'submitted'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }
}
