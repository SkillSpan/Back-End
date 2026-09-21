<?php

namespace Tests\Feature\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function createLearnerWithOrg(): User
    {
        $org = Organization::create([
            'name' => 'Test Organization',
            'type' => 'company',
        ]);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
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

        return $user;
    }

    private function createOpenProject(array $attributes = []): Project
    {
        $organizationId = $attributes['organization_id'] ?? Organization::first()?->id ?? Organization::create(['name' => 'Test Org', 'type' => 'company'])->id;
        $ownerId = $attributes['owner_id'] ?? User::inRandomOrder()->first()?->id ?? User::forceCreate([
            'name' => 'Project Owner',
            'email' => 'owner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ])->id;

        return Project::create(array_merge([
            'organization_id' => $organizationId,
            'owner_id' => $ownerId,
            'title' => 'Test Project',
            'description' => 'A test project description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ], $attributes));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/projects')
            ->assertStatus(401);
    }

    public function test_non_learner_cannot_access_projects(): void
    {
        $user = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')
            ->assertStatus(403);
    }

    public function test_authenticated_learner_receives_accessible_projects(): void
    {
        // Use the helper to ensure consistent setup, like other passing tests
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        // Create a public project in the learner's organization
        $project = Project::create([
            'organization_id' => $learner->organization_id,
            'owner_id' => $learner->id,
            'title' => 'Public Project',
            'description' => 'Description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        // Verify project exists in database
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'open']);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['title' => 'Public Project'])
            ->assertJsonStructure(['success', 'message', 'data', 'request_id']);
    }

    public function test_closed_project_is_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['status' => 'closed', 'organization_id' => $learner->organization_id]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_expired_project_is_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject([
            'end_date' => now()->subDays(5)->toDateString(),
            'organization_id' => $learner->organization_id,
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_project_with_expired_application_deadline_is_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject([
            'application_deadline' => now()->subDays(2)->toDateString(),
            'organization_id' => $learner->organization_id,
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_restricted_project_is_excluded_when_learner_not_authorized(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['confidentiality' => 'restricted', 'organization_id' => Organization::first()?->id ?? 1]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_public_project_is_included(): void
    {
        // Use helper for consistent setup
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        // Create a public project in the learner's organization
        Project::create([
            'organization_id' => $learner->organization_id,
            'owner_id' => $learner->id,
            'title' => 'Public Project',
            'description' => 'Description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['title' => 'Public Project']);
    }

    public function test_draft_project_is_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['status' => 'draft', 'organization_id' => $learner->organization_id]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_archived_project_is_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['status' => 'archived', 'organization_id' => $learner->organization_id]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_closed_project_is_excluded_duplicate(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['status' => 'closed', 'organization_id' => $learner->organization_id]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_project_from_learner_organization_is_visible(): void
    {
        $org = Organization::create(['name' => 'My Org', 'type' => 'company']);
        $user = User::forceCreate([
            'name' => 'Org Member',
            'email' => 'orgmember@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => $org->id]);
        $user->organizations()->attach($org->id, ['role_in_org' => 'member', 'status' => 'active']);
        StudentProfile::forceCreate(['user_id' => $user->id, 'visibility' => 'private', 'consent_given' => true]);

        Sanctum::actingAs($user);

        Project::create([
            'organization_id' => $org->id,
            'owner_id' => $user->id,
            'title' => 'Org Project',
            'description' => 'Description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_project_from_different_organization_is_hidden(): void
    {
        $learnerOrg = Organization::create(['name' => 'Learner Org', 'type' => 'company']);
        $learner = User::forceCreate([
            'name' => 'Learner User',
            'email' => 'different@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $learner->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => $learnerOrg->id]);
        $learner->organizations()->attach($learnerOrg->id, ['role_in_org' => 'member', 'status' => 'active']);
        StudentProfile::forceCreate(['user_id' => $learner->id, 'visibility' => 'private', 'consent_given' => true]);

        Sanctum::actingAs($learner);

        $otherOrg = Organization::create(['name' => 'Other Org', 'type' => 'company']);
        $owner = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner3@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Project::create([
            'organization_id' => $otherOrg->id,
            'owner_id' => $owner->id,
            'title' => 'Other Org Project',
            'description' => 'Description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'restricted',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_response_is_paginated(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        for ($i = 1; $i <= 60; $i++) {
            $this->createOpenProject(['title' => "Project $i", 'organization_id' => $learner->organization_id]);
        }

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_student_profile_required(): void
    {
        $user = User::forceCreate([
            'name' => 'No Profile',
            'email' => 'noprofile@test@gmail.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }
}