<?php

namespace Tests\Feature\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\Role;
use App\Models\Skill;
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

    /**
     * Build a learner and two public open projects with distinct
     * text/type/domain/work_mode/difficulty values for filter tests.
     */
    private function createFilterFixtures(): array
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $laravel = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Laravel API Service',
            'description' => 'Build a backend REST API with Laravel.',
            'type' => 'company_sponsored',
            'domain' => 'backend',
            'work_mode' => 'remote',
            'difficulty' => 3.5,
        ]);

        $react = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'React Marketing Dashboard',
            'description' => 'Frontend dashboard built with React.',
            'type' => 'simulation',
            'domain' => 'frontend',
            'work_mode' => 'hybrid',
            'difficulty' => 2.5,
        ]);

        return ['learner' => $learner, 'laravel' => $laravel, 'react' => $react];
    }

    public function test_no_filters_returns_all_accessible_projects(): void
    {
        $fixtures = $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_search_filters_by_keyword(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?search=laravel');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_search_matches_description(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?search=frontend');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'React Marketing Dashboard'])
            ->assertJsonMissing(['title' => 'Laravel API Service']);
    }

    public function test_type_filter(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?type=simulation');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'React Marketing Dashboard'])
            ->assertJsonMissing(['title' => 'Laravel API Service']);
    }

    public function test_domain_filter(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?domain=backend');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_work_mode_filter(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?work_mode=remote');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_difficulty_filter(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?difficulty=3.5');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_combined_filters(): void
    {
        $this->createFilterFixtures();

        $response = $this->getJson('/api/v1/projects?type=simulation&domain=frontend');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'React Marketing Dashboard'])
            ->assertJsonMissing(['title' => 'Laravel API Service']);
    }

    public function test_invalid_type_filter_is_rejected(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?type=hackathon')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_invalid_difficulty_filter_is_rejected(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?difficulty=99')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_non_existent_organization_id_is_rejected(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?organization_id=999999')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_skill_filter_returns_only_matching_projects(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $js = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript']);

        $phpProject = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'PHP Backend Service',
        ]);
        $jsProject = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'JS Frontend App',
        ]);

        ProjectRequiredSkill::create(['project_id' => $phpProject->id, 'skill_id' => $php->id, 'minimum_level' => 3.0]);
        ProjectRequiredSkill::create(['project_id' => $jsProject->id, 'skill_id' => $js->id, 'minimum_level' => 2.0]);

        $response = $this->getJson('/api/v1/projects?skill_ids[]='.$php->id);

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'PHP Backend Service'])
            ->assertJsonMissing(['title' => 'JS Frontend App']);
    }

    public function test_skill_filter_requires_all_skills(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php-two']);
        $js = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript-two']);

        // Only project A requires both PHP + JS.
        $projectA = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Full Stack App',
        ]);
        $projectB = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'PHP Only App',
        ]);

        ProjectRequiredSkill::create(['project_id' => $projectA->id, 'skill_id' => $php->id, 'minimum_level' => 3.0]);
        ProjectRequiredSkill::create(['project_id' => $projectA->id, 'skill_id' => $js->id, 'minimum_level' => 2.0]);
        ProjectRequiredSkill::create(['project_id' => $projectB->id, 'skill_id' => $php->id, 'minimum_level' => 3.0]);

        $response = $this->getJson('/api/v1/projects?skill_ids[]='.$php->id.'&skill_ids[]='.$js->id);

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Full Stack App'])
            ->assertJsonMissing(['title' => 'PHP Only App']);
    }

    public function test_minimum_level_filter(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php-level']);
        $js = Skill::create(['name' => 'JavaScript', 'slug' => 'js-level']);

        // Project A requires PHP at level 2; project B requires PHP at level 4.
        $projectA = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Easy PHP App',
        ]);
        $projectB = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Hard PHP App',
        ]);

        ProjectRequiredSkill::create(['project_id' => $projectA->id, 'skill_id' => $php->id, 'minimum_level' => 2.0]);
        ProjectRequiredSkill::create(['project_id' => $projectB->id, 'skill_id' => $php->id, 'minimum_level' => 4.0]);

        $response = $this->getJson('/api/v1/projects?skill_ids[]='.$php->id.'&minimum_level=3');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Hard PHP App'])
            ->assertJsonMissing(['title' => 'Easy PHP App']);
    }

    public function test_non_existent_skill_id_is_rejected(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?skill_ids[]=999999')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_matching_unauthorized_restricted_project_stays_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $hiddenOrg = Organization::create(['name' => 'Hidden Org', 'type' => 'company']);
        $owner = User::forceCreate([
            'name' => 'Hidden Owner',
            'email' => 'hiddenowner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Project::create([
            'organization_id' => $hiddenOrg->id,
            'owner_id' => $owner->id,
            'title' => 'Secret Restricted Project',
            'description' => 'A secret project matching the search term.',
            'type' => 'simulation',
            'domain' => 'backend',
            'status' => 'open',
            'confidentiality' => 'restricted',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $response = $this->getJson('/api/v1/projects?search=secret');

        $response->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonMissing(['title' => 'Secret Restricted Project']);
    }

    public function test_matching_closed_project_stays_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Closed Laravel Project',
            'type' => 'simulation',
            'domain' => 'backend',
            'status' => 'closed',
        ]);

        $response = $this->getJson('/api/v1/projects?search=laravel');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_matching_expired_project_stays_excluded(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Expired Laravel Project',
            'type' => 'simulation',
            'domain' => 'backend',
            'status' => 'open',
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/projects?search=laravel');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_learner_still_retrieves_valid_projects_with_filters(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Accessible Laravel Project',
            'type' => 'simulation',
            'domain' => 'backend',
        ]);

        $response = $this->getJson('/api/v1/projects?search=laravel&type=simulation');

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Accessible Laravel Project']);
    }

    public function test_authenticated_learner_can_access_valid_project_details(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $project = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Detail Project',
            'type' => 'simulation',
            'domain' => 'backend',
            'work_mode' => 'remote',
            'difficulty' => 3.5,
            'capacity' => 4,
            'min_team_size' => 2,
        ]);

        $response = $this->getJson('/api/v1/projects/'.$project->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project details retrieved successfully.')
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.title', 'Detail Project')
            ->assertJsonPath('data.type', 'simulation')
            ->assertJsonPath('data.domain', 'backend')
            ->assertJsonPath('data.work_mode', 'remote')
            ->assertJsonPath('data.difficulty', 3.5)
            ->assertJsonPath('data.capacity', 4)
            ->assertJsonPath('data.min_team_size', 2)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'title',
                    'description',
                    'type',
                    'domain',
                    'objectives',
                    'learning_outcomes',
                    'difficulty',
                    'work_mode',
                    'role',
                    'schedule',
                    'capacity',
                    'min_team_size',
                    'application_deadline',
                    'start_date',
                    'end_date',
                    'status',
                    'confidentiality',
                    'version',
                    'organization',
                    'required_skills',
                ],
                'request_id',
            ]);
    }

    public function test_unauthenticated_project_details_request_is_rejected(): void
    {
        $this->getJson('/api/v1/projects/1')
            ->assertStatus(401);
    }

    public function test_non_learner_cannot_access_project_details(): void
    {
        $user = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin-details@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects/1')
            ->assertStatus(403);
    }

    public function test_missing_learner_profile_project_details_rejected(): void
    {
        $user = User::forceCreate([
            'name' => 'No Profile',
            'email' => 'noprofile-details@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects/1')
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }

    public function test_nonexistent_project_returns_404(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_learner_cannot_access_another_organizations_restricted_project(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $otherOrg = Organization::create(['name' => 'Other Details Org', 'type' => 'company']);
        $owner = User::forceCreate([
            'name' => 'Other Owner',
            'email' => 'other-owner-details@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $restricted = Project::create([
            'organization_id' => $otherOrg->id,
            'owner_id' => $owner->id,
            'title' => 'Restricted Project',
            'description' => 'Restricted description',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'restricted',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        $this->getJson('/api/v1/projects/'.$restricted->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_UNAUTHORIZED');
    }

    public function test_learner_can_access_allowed_restricted_project(): void
    {
        $learner = $this->createLearnerWithOrg();
        $orgId = $learner->organization_id;

        $owner = User::forceCreate([
            'name' => 'Same Org Owner',
            'email' => 'same-org-owner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $restricted = Project::create([
            'organization_id' => $orgId,
            'owner_id' => $owner->id,
            'title' => 'Own Org Restricted Project',
            'description' => 'Restricted but in learner org',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'restricted',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects/'.$restricted->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $restricted->id)
            ->assertJsonPath('data.confidentiality', 'restricted');
    }

    public function test_closed_project_details_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $project = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Closed Detail Project',
            'status' => 'closed',
        ]);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_UNAUTHORIZED');
    }

    public function test_expired_project_details_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $project = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Expired Detail Project',
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_UNAUTHORIZED');
    }

    public function test_direct_project_id_cannot_bypass_authorization(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $otherOrg = Organization::create(['name' => 'Bypass Org', 'type' => 'company']);
        $owner = User::forceCreate([
            'name' => 'Bypass Owner',
            'email' => 'bypass-owner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $hidden = Project::create([
            'organization_id' => $otherOrg->id,
            'owner_id' => $owner->id,
            'title' => 'Hidden Project',
            'description' => 'Must stay hidden',
            'type' => 'simulation',
            'status' => 'open',
            'confidentiality' => 'restricted',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);

        // Even though the ID is valid and the project exists, the learner
        // is not authorized — must NOT be exposed via the details endpoint.
        $this->getJson('/api/v1/projects/'.$hidden->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_UNAUTHORIZED');
    }

    public function test_required_skills_are_returned_with_levels(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php-details']);
        $js = Skill::create(['name' => 'JavaScript', 'slug' => 'js-details']);

        $project = $this->createOpenProject([
            'organization_id' => $learner->organization_id,
            'title' => 'Skill Project',
        ]);

        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $php->id, 'minimum_level' => 3.0, 'is_critical_entry' => true]);
        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $js->id, 'minimum_level' => 2.0, 'is_critical_entry' => false]);

        $response = $this->getJson('/api/v1/projects/'.$project->id);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.required_skills')
            ->assertJsonFragment([
                'skill_id' => $php->id,
                'skill_name' => 'PHP',
                'minimum_level' => 3.0,
                'is_critical_entry' => true,
            ])
            ->assertJsonFragment([
                'skill_id' => $js->id,
                'skill_name' => 'JavaScript',
                'minimum_level' => 2.0,
                'is_critical_entry' => false,
            ]);
    }
}