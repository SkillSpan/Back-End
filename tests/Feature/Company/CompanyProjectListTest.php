<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The company's own project list and detail.
 *
 * Two rules carry the whole file:
 *
 *   1. **Scoping.** The organization comes from the caller's admin membership,
 *      never from a request parameter. A project belonging to another company
 *      must be invisible — and it must answer 404, not 403, because a 403
 *      would confirm that the id exists.
 *   2. **The response is usable by the editor.** `is_editable`,
 *      `project_roles` (including inactive ones) and `eligibility_constraints`
 *      are what the edit form renders; without them the form cannot round-trip
 *      a project without silently dropping data.
 */
class CompanyProjectListTest extends TestCase
{
    use RefreshDatabase;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyAdminRole = Role::create([
            'name' => 'Company Admin',
            'slug' => 'company_admin',
            'description' => '',
        ]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(string $name, string $status = 'verified'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function companyAdmin(Organization $organization, string $roleInOrg = 'admin'): User
    {
        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('company-admin-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->companyAdminRole->id, ['organization_id' => $organization->id]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => $roleInOrg,
            'status' => 'active',
        ]);

        return $user;
    }

    private function project(Organization $organization, User $owner, string $status = Project::STATUS_DRAFT, string $title = 'Project'): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'title' => $title,
            'description' => 'Described.',
            'objectives' => 'Scoped.',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => 3,
            'work_mode' => 'remote',
            'difficulty' => 3,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);
    }

    // -----------------------------------------------------------------
    // List
    // -----------------------------------------------------------------

    public function test_the_list_returns_only_the_callers_own_organization_projects(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        $this->project($mine, $admin, Project::STATUS_DRAFT, 'Mine One');
        $this->project($mine, $admin, Project::STATUS_OPEN, 'Mine Two');
        $this->project($theirs, $otherAdmin, Project::STATUS_OPEN, 'Theirs');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/projects')->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();

        sort($titles);

        $this->assertSame(['Mine One', 'Mine Two'], $titles);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_the_list_is_paginated_and_reports_the_page_size_it_used(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        foreach (range(1, 5) as $i) {
            $this->project($organization, $admin, Project::STATUS_DRAFT, "Project {$i}");
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/projects?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(3, $response->json('meta.last_page'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_per_page_is_clamped_to_one_hundred(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/projects?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_the_list_filters_by_status(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        $this->project($organization, $admin, Project::STATUS_DRAFT, 'Draft One');
        $this->project($organization, $admin, Project::STATUS_OPEN, 'Open One');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/projects?status=open')->assertOk();

        $this->assertSame(['Open One'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_an_unknown_status_filter_is_ignored_rather_than_rejected(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        $this->project($organization, $admin, Project::STATUS_DRAFT, 'Draft One');

        Sanctum::actingAs($admin);

        // A stale bookmark must not 422 the whole screen.
        $this->getJson('/api/v1/company/projects?status=not_a_status')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_list_searches_the_title(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        $this->project($organization, $admin, Project::STATUS_DRAFT, 'Dashboard Revamp');
        $this->project($organization, $admin, Project::STATUS_DRAFT, 'Mobile App');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/projects?q=Dashboard')->assertOk();

        $this->assertSame(['Dashboard Revamp'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_the_stats_block_reports_every_status_for_this_organization_only(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        $this->project($mine, $admin, Project::STATUS_DRAFT);
        $this->project($mine, $admin, Project::STATUS_DRAFT);
        $this->project($mine, $admin, Project::STATUS_OPEN);
        $this->project($theirs, $otherAdmin, Project::STATUS_OPEN);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/projects')->assertOk();

        $this->assertSame(3, $response->json('stats.total'));
        $this->assertSame(2, $response->json('stats.by_status.draft'));
        $this->assertSame(1, $response->json('stats.by_status.open'));
        $this->assertSame(0, $response->json('stats.by_status.completed'));

        // Every one of the twelve statuses is present, even at zero, so the UI
        // can render a stable grid.
        $this->assertCount(12, $response->json('stats.by_status'));
    }

    // -----------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------

    public function test_the_detail_returns_the_project_with_the_editor_fields(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        $project = $this->project($organization, $admin, Project::STATUS_DRAFT, 'Editable One');

        $project->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);
        $project->projectRoles()->create(['title' => 'Retired Role', 'is_active' => false]);
        $project->eligibilityConstraints()->create(['constraint_type' => 'location', 'value' => 'Damascus']);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/company/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.title', 'Editable One')
            ->assertJsonPath('data.is_editable', true);

        // Every role is returned, including the inactive one: an editor must
        // see what is stored or it would be dropped on save.
        $this->assertCount(2, $response->json('data.project_roles'));

        $this->assertSame('Damascus', $response->json('data.eligibility_constraints.0.value'));
    }

    public function test_a_project_outside_the_window_is_reported_as_not_editable(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        $project = $this->project($organization, $admin, Project::STATUS_OPEN);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/company/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', false);
    }

    public function test_another_organizations_project_answers_404_not_403(): void
    {
        $mine = $this->organization('Acme');
        $theirs = $this->organization('Globex');

        $admin = $this->companyAdmin($mine);
        $otherAdmin = $this->companyAdmin($theirs);

        $foreign = $this->project($theirs, $otherAdmin, Project::STATUS_OPEN);

        Sanctum::actingAs($admin);

        // 403 would confirm the id exists and make the route an oracle.
        $this->getJson("/api/v1/company/projects/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_an_unknown_project_answers_404(): void
    {
        $organization = $this->organization('Acme');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/projects/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_a_member_who_is_not_an_organization_admin_is_refused(): void
    {
        $organization = $this->organization('Acme');
        $member = $this->companyAdmin($organization, 'member');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/company/projects')
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_ORGANIZATION_ADMIN');
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson('/api/v1/company/projects')->assertStatus(401);
    }

    public function test_a_pending_organization_is_refused_before_the_controller_runs(): void
    {
        $organization = $this->organization('Acme', 'pending');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/projects')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }
}
