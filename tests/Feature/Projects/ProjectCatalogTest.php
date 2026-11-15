<?php

namespace Tests\Feature\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectRole;
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
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

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

        $otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'type' => 'company',
        ]);

        $restricted = $this->createOpenProject([
            'confidentiality' => 'restricted',
            'organization_id' => $otherOrganization->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $restricted->id,
            'confidentiality' => 'restricted',
            'organization_id' => $otherOrganization->id,
        ]);

        $this->assertFalse(
            $learner->organizations()->where('organization_id', $otherOrganization->id)->exists(),
            'The learner must not be a member of the other organization.',
        );

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_public_project_is_included(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

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
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 60)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertCount(50, $response->json('data'));
    }

    public function test_pagination_page_and_per_page_are_applied(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        for ($i = 1; $i <= 5; $i++) {
            $this->createOpenProject([
                'title' => "Paged Project {$i}",
                'created_at' => now()->addMinutes($i),
                'organization_id' => $learner->organization_id,
            ]);
        }

        $response = $this->getJson('/api/v1/projects?per_page=2&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_search_filters_and_pagination_work_together(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['title' => 'Laravel Remote One', 'domain' => 'backend', 'work_mode' => 'remote']);
        $this->createOpenProject(['title' => 'Laravel Remote Two', 'domain' => 'backend', 'work_mode' => 'remote']);
        $this->createOpenProject(['title' => 'Laravel Onsite', 'domain' => 'backend', 'work_mode' => 'onsite']);
        $this->createOpenProject(['title' => 'React Remote', 'domain' => 'frontend', 'work_mode' => 'remote']);

        $response = $this->getJson('/api/v1/projects?search=Laravel&domain=backend&work_mode=remote&per_page=1&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('Laravel Remote', $response->json('data.0.title'));
    }

    public function test_discovery_does_not_filter_by_hard_eligibility(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $skill = Skill::create(['name' => 'Rust', 'slug' => 'rust', 'category' => 'backend']);

        $project = $this->createOpenProject([
            'title' => 'Needs Rust',
            'organization_id' => $learner->organization_id,
        ]);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        $this->assertDatabaseCount('skill_evaluations', 0);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_pagination_is_deterministic_when_created_at_is_identical(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $sameMoment = now()->subMinutes(5);
        $ids = [];

        for ($i = 1; $i <= 5; $i++) {
            $project = $this->createOpenProject([
                'title' => "Tied Project {$i}",
                'organization_id' => $learner->organization_id,
            ]);

            Project::query()->whereKey($project->id)->update(['created_at' => $sameMoment]);

            $ids[] = $project->id;
        }

        $expected = [array_reverse($ids)[0], array_reverse($ids)[1], array_reverse($ids)[2], array_reverse($ids)[3], array_reverse($ids)[4]];

        $collected = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("/api/v1/projects?per_page=2&page={$page}");

            $response->assertStatus(200)
                ->assertJsonPath('meta.total', 5)
                ->assertJsonPath('meta.last_page', 3);

            foreach ($response->json('data') as $row) {
                $collected[] = $row['id'];
            }
        }

        $this->assertSame($expected, $collected);
        $this->assertCount(5, array_unique($collected), 'A project must not appear on two pages.');
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects?per_page=51')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/projects?per_page=0')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_invalid_page_is_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects?page=0')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/projects?page=not-a-number')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_empty_result_set_returns_empty_data_with_zeroed_metadata(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_discovery_response_keeps_the_established_envelope(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->createOpenProject(['title' => 'Envelope Check', 'organization_id' => $learner->organization_id]);

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'title', 'description', 'type', 'status', 'version']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'request_id',
            ]);

        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('data', $response->json('data'));
    }

    public function test_full_project_capacity_does_not_change_discovery_under_current_rules(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $project = $this->createOpenProject([
            'title' => 'Capacity Filled But Still Open',
            'capacity' => 0,
        ]);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_duplicate_skill_ids_are_treated_as_one_required_skill(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php-duplicate']);
        $project = $this->createOpenProject(['title' => 'PHP Only']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $php->id,
            'minimum_level' => 3.0,
        ]);

        $this->getJson('/api/v1/projects?skill_ids[]='.$php->id)
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/projects?skill_ids[]='.$php->id.'&skill_ids[]='.$php->id)
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_minimum_level_without_skill_ids_is_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects?minimum_level=3')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('details.errors.minimum_level.0', 'The minimum_level filter requires skill_ids.');
    }

    public function test_minimum_level_of_zero_without_skill_ids_is_also_rejected(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/projects?minimum_level=0')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_minimum_level_with_skill_ids_still_filters_normally(): void
    {
        $learner = $this->createLearnerWithOrg();
        Sanctum::actingAs($learner);

        $php = Skill::create(['name' => 'PHP', 'slug' => 'php-minlevel']);

        $strict = $this->createOpenProject(['title' => 'Strict']);
        ProjectRequiredSkill::create([
            'project_id' => $strict->id,
            'skill_id' => $php->id,
            'minimum_level' => 4.0,
        ]);

        $loose = $this->createOpenProject(['title' => 'Loose']);
        ProjectRequiredSkill::create([
            'project_id' => $loose->id,
            'skill_id' => $php->id,
            'minimum_level' => 2.0,
        ]);

        $this->getJson('/api/v1/projects?skill_ids[]='.$php->id.'&minimum_level=3')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $strict->id);
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

    public function test_role_filter_matches_the_headline_role_column(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        $response = $this->getJson('/api/v1/projects?'.http_build_query(['role' => 'Backend Developer']));

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_role_filter_matches_an_active_project_role_title(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        ProjectRole::create([
            'project_id' => $fixtures['laravel']->id,
            'title' => 'API Developer',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/projects?'.http_build_query(['role' => 'API Developer']));

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Laravel API Service'])
            ->assertJsonMissing(['title' => 'React Marketing Dashboard']);
    }

    public function test_role_filter_ignores_a_deactivated_project_role(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        ProjectRole::create([
            'project_id' => $fixtures['laravel']->id,
            'title' => 'Retired Role',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/projects?'.http_build_query(['role' => 'Retired Role']));

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_role_filter_is_optional_and_absent_role_returns_everything(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        $this->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_role_filter_returns_an_empty_page_for_an_unknown_role(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?role=Astronaut')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_role_filter_does_not_widen_visibility_rules(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer', 'status' => 'draft']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        $response = $this->getJson('/api/v1/projects?'.http_build_query(['role' => 'Backend Developer']));

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_role_filter_combines_with_other_filters(): void
    {
        $fixtures = $this->createFilterFixtures();

        $fixtures['laravel']->update(['role' => 'Backend Developer']);
        $fixtures['react']->update(['role' => 'Frontend Developer']);

        $response = $this->getJson('/api/v1/projects?'.http_build_query(['role' => 'Frontend Developer', 'type' => 'simulation']));

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'React Marketing Dashboard'])
            ->assertJsonMissing(['title' => 'Laravel API Service']);
    }

    public function test_invalid_role_filter_is_rejected(): void
    {
        $this->createFilterFixtures();

        $this->getJson('/api/v1/projects?role='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
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

        $organization = $learner->organizations()
            ->wherePivot('status', 'active')
            ->first();

        $this->assertNotNull(
            $organization,
            'The learner must be an active member of an organization.',
        );

        $owner = User::forceCreate([
            'name' => 'Same Org Owner',
            'email' => 'same-org-owner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $restricted = Project::create([
            'organization_id' => $organization->id,
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
