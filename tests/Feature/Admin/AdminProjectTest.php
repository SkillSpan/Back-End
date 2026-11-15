<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\ProjectResource;
use App\Models\CareerRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProjectTest extends TestCase
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

    private function admin(string $email = 'admin@example.com'): User
    {
        return $this->user($email, 'admin');
    }

    private function organization(string $name = 'Acme Co'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'verified',
        ]);
    }

    private function careerRole(): CareerRole
    {
        return CareerRole::first() ?? CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer',
            'version' => 1,
            'status' => 'approved',
        ]);
    }

    private function project(array $attributes = []): Project
    {
        $owner = $attributes['owner_id'] ?? $this->user('owner'.uniqid().'@example.com', 'company_admin')->id;

        return Project::create(array_merge([
            'organization_id' => null,
            'owner_id' => $owner,
            'type' => 'simulation',
            'title' => 'Sample Project',
            'description' => 'A sample project.',
            'difficulty' => 2.5,
            'capacity' => 5,
            'status' => Project::STATUS_DRAFT,
            'confidentiality' => 'public',
            'version' => 1,
        ], $attributes));
    }

    private function completeProject(string $status): Project
    {
        $project = $this->project([
            'status' => $status,
            'description' => 'A complete project.',
            'objectives' => 'Prove the workflow.',
            'capacity' => 4,
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'application_deadline' => now()->addWeeks(3)->toDateString(),
        ]);

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php-'.uniqid(), 'category' => 'backend']);

        $project->requiredSkills()->create([
            'skill_id' => $skill->id,
            'minimum_level' => 2.0,
            'is_critical_entry' => false,
        ]);

        $project->projectRoles()->create([
            'title' => 'Backend Developer',
            'description' => 'Builds the API.',
            'is_active' => true,
        ]);

        return $project->fresh();
    }

    public function test_guests_cannot_list_projects(): void
    {
        $this->getJson('/api/v1/admin/projects')->assertStatus(401);
    }

    public function test_a_non_admin_cannot_list_projects(): void
    {
        Sanctum::actingAs($this->user('learner@example.com', 'learner'));

        $this->getJson('/api/v1/admin/projects')->assertStatus(403);
    }

    public function test_a_non_admin_cannot_cancel_a_project(): void
    {
        $project = $this->project();
        Sanctum::actingAs($this->user('owner2@example.com', 'company_admin'));

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel")
            ->assertStatus(403);

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_the_list_says_which_projects_are_editable(): void
    {
        Sanctum::actingAs($this->admin());
        $draft = $this->project(['status' => Project::STATUS_DRAFT]);
        $open = $this->project(['status' => Project::STATUS_OPEN]);

        $response = $this->getJson('/api/v1/admin/projects')->assertOk();

        $rows = collect($response->json('data.data'))->keyBy('id');

        $this->assertTrue($rows[$draft->id]['is_editable']);
        $this->assertFalse($rows[$open->id]['is_editable']);
    }

    public function test_the_detail_says_whether_the_project_is_editable(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_CHANGES_REQUESTED]);

        $this->getJson("/api/v1/admin/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', true);
    }

    public function test_the_admin_payload_carries_every_role_including_inactive_ones(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_DRAFT]);

        $project->projectRoles()->create(['title' => 'Active Role', 'is_active' => true]);
        $project->projectRoles()->create(['title' => 'Retired Role', 'is_active' => false]);

        $data = $this->getJson("/api/v1/admin/projects/{$project->id}")->assertOk()->json('data');

        $available = collect($data['available_project_roles'])->pluck('title')->all();
        $this->assertSame(['Active Role'], $available);

        $all = collect($data['project_roles'])->pluck('title')->all();
        $this->assertContains('Active Role', $all);
        $this->assertContains('Retired Role', $all);

        $inactive = collect($data['project_roles'])->firstWhere('title', 'Retired Role');
        $this->assertFalse($inactive['is_active']);
    }

    public function test_the_admin_payload_carries_the_stored_eligibility_constraints(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_DRAFT]);

        $project->eligibilityConstraints()->create([
            'constraint_type' => 'location',
            'value' => 'Amman',
        ]);

        $data = $this->getJson("/api/v1/admin/projects/{$project->id}")->assertOk()->json('data');

        $this->assertSame('location', $data['eligibility_constraints'][0]['constraint_type']);
        $this->assertSame('Amman', $data['eligibility_constraints'][0]['value']);
    }

    public function test_the_learner_catalog_does_not_gain_the_admin_only_fields(): void
    {
        $project = $this->project(['status' => Project::STATUS_OPEN]);
        $project->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);
        $project->eligibilityConstraints()->create(['constraint_type' => 'location', 'value' => 'Amman']);
        $project->load(['projectRoles', 'eligibilityConstraints']);

        $data = (new ProjectResource($project))->toArray(request());

        $this->assertArrayNotHasKey('is_editable', $data);
        $this->assertArrayNotHasKey('project_roles', $data);
        $this->assertArrayNotHasKey('eligibility_constraints', $data);

        $this->assertSame(['Backend Developer'], collect($data['available_project_roles'])->pluck('title')->all());
    }

    public function test_an_admin_can_list_projects_including_drafts(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'Draft One', 'status' => Project::STATUS_DRAFT]);
        $this->project(['title' => 'Submitted One', 'status' => Project::STATUS_SUBMITTED]);

        $response = $this->getJson('/api/v1/admin/projects')->assertOk();

        $titles = collect($response->json('data.data'))->pluck('title');
        $this->assertTrue($titles->contains('Draft One'));
        $this->assertTrue($titles->contains('Submitted One'));
    }

    public function test_the_list_is_paginated_with_the_real_envelope(): void
    {
        Sanctum::actingAs($this->admin());

        for ($i = 1; $i <= 18; $i++) {
            $this->project(['title' => "Project {$i}"]);
        }

        $response = $this->getJson('/api/v1/admin/projects?per_page=5')->assertOk();

        $response->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 5)
            ->assertJsonPath('data.meta.total', 18)
            ->assertJsonPath('data.meta.last_page', 4);

        $this->assertCount(5, $response->json('data.data'));
    }

    public function test_per_page_is_clamped(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project();

        $this->getJson('/api/v1/admin/projects?per_page=10000')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 100);

        $this->getJson('/api/v1/admin/projects?per_page=0')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 1);
    }

    public function test_search_matches_the_title(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'Atlas Migration']);
        $this->project(['title' => 'Unrelated Work']);

        $titles = collect($this->getJson('/api/v1/admin/projects?q=atlas')->assertOk()->json('data.data'))
            ->pluck('title');

        $this->assertSame(['Atlas Migration'], $titles->all());
    }

    public function test_search_accepts_a_numeric_project_id(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->project(['title' => 'Findable By Id']);
        $this->project(['title' => 'Something Else']);

        $ids = collect($this->getJson("/api/v1/admin/projects?q={$target->id}")->assertOk()->json('data.data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($target->id));
    }

    public function test_a_hostile_search_term_is_treated_as_a_literal(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'Atlas Migration']);
        $this->project(['title' => 'Unrelated Work']);

        foreach ([
            "'; DROP TABLE projects; --",
            "' OR 1=1 --",
            "Atlas%' UNION SELECT 1 --",
        ] as $term) {
            $response = $this->getJson('/api/v1/admin/projects?q='.urlencode($term))->assertOk();

            $this->assertSame([], $response->json('data.data'));
            $this->assertSame(2, $response->json('stats.total'));
        }

        $this->assertSame(2, Project::count());
    }

    public function test_projects_can_be_filtered_by_status(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'A Draft', 'status' => Project::STATUS_DRAFT]);
        $this->project(['title' => 'An Approved', 'status' => Project::STATUS_APPROVED]);

        $statuses = collect($this->getJson('/api/v1/admin/projects?status=approved')->assertOk()->json('data.data'))
            ->pluck('status')
            ->unique();

        $this->assertSame([Project::STATUS_APPROVED], $statuses->values()->all());
    }

    public function test_projects_can_be_filtered_by_type(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'Sim', 'type' => 'simulation']);
        $this->project([
            'title' => 'Sponsored',
            'type' => 'company_sponsored',
            'organization_id' => $this->organization()->id,
        ]);

        $types = collect($this->getJson('/api/v1/admin/projects?type=company_sponsored')->assertOk()->json('data.data'))
            ->pluck('type')
            ->unique();

        $this->assertSame(['company_sponsored'], $types->values()->all());
    }

    public function test_projects_can_be_filtered_by_difficulty(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['title' => 'Easy', 'difficulty' => 1.0]);
        $this->project(['title' => 'Hard', 'difficulty' => 4.5]);

        $titles = collect($this->getJson('/api/v1/admin/projects?difficulty=4.5')->assertOk()->json('data.data'))
            ->pluck('title');

        $this->assertSame(['Hard'], $titles->all());
    }

    public function test_an_unknown_filter_value_is_ignored_rather_than_erroring(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project();

        $this->getJson('/api/v1/admin/projects?status=not_a_real_status')->assertOk();
    }

    public function test_the_applied_filters_are_echoed_back(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/projects?q=abc&status=draft')
            ->assertOk()
            ->assertJsonPath('filters.q', 'abc')
            ->assertJsonPath('filters.status', 'draft');
    }

    public function test_the_stats_count_the_whole_table_not_the_current_page(): void
    {
        Sanctum::actingAs($this->admin());

        for ($i = 0; $i < 20; $i++) {
            $this->project(['status' => Project::STATUS_DRAFT]);
        }
        $this->project(['status' => Project::STATUS_SUBMITTED]);
        $this->project(['status' => Project::STATUS_CHANGES_REQUESTED]);
        $this->project(['status' => Project::STATUS_OPEN]);

        $this->getJson('/api/v1/admin/projects?per_page=10')
            ->assertOk()
            ->assertJsonPath('stats.total', 23)
            ->assertJsonPath('stats.draft', 20)
            ->assertJsonPath('stats.awaiting_review', 2)
            ->assertJsonPath('stats.open', 1);
    }

    public function test_the_stats_are_not_narrowed_by_the_active_filters(): void
    {
        Sanctum::actingAs($this->admin());
        $this->project(['status' => Project::STATUS_DRAFT]);
        $this->project(['status' => Project::STATUS_OPEN]);

        $this->getJson('/api/v1/admin/projects?status=open')
            ->assertOk()
            ->assertJsonPath('stats.total', 2)
            ->assertJsonPath('stats.draft', 1)
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_an_admin_can_view_a_single_project_with_its_relations(): void
    {
        Sanctum::actingAs($this->admin());
        $organization = $this->organization('Sponsor Ltd');
        $project = $this->project([
            'title' => 'Detailed Project',
            'organization_id' => $organization->id,
            'type' => 'company_sponsored',
        ]);

        $this->getJson("/api/v1/admin/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.title', 'Detailed Project')
            ->assertJsonPath('data.type', 'company_sponsored')
            ->assertJsonPath('data.organization.title', 'Sponsor Ltd')
            ->assertJsonPath('success', true);
    }

    public function test_viewing_a_missing_project_returns_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/projects/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_an_admin_can_cancel_a_project_and_the_row_survives(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_DRAFT]);

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_CANCELLED);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => Project::STATUS_CANCELLED,
        ]);
    }

    public function test_cancelling_can_carry_a_reason(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project();

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel", [
            'reason' => 'Superseded by a newer brief.',
        ])->assertOk();

        $this->assertSame(Project::STATUS_CANCELLED, $project->fresh()->status);
    }

    public function test_an_already_cancelled_project_cannot_be_cancelled_again(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_CANCELLED]);

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_CANCELLABLE');
    }

    public function test_a_rejected_project_cannot_be_cancelled(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_REJECTED]);

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_CANCELLABLE');
    }

    public function test_cancelling_an_unknown_project_returns_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/projects/999999/cancel')->assertStatus(404);
    }

    public function test_cancelling_writes_an_audit_entry(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        $project = $this->project();

        $this->postJson("/api/v1/admin/projects/{$project->id}/cancel")->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'project.cancelled',
            'entity_type' => 'project',
            'entity_id' => $project->id,
        ]);
    }

    public function test_lifecycle_actions_remain_available_to_an_admin(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->completeProject(Project::STATUS_SUBMITTED);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertOk();

        $this->assertSame(Project::STATUS_APPROVED, $project->fresh()->status);
    }

    public function test_a_completeness_gap_blocks_approval_with_a_useful_code(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_SUBMITTED]);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE');

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_an_admin_can_approve_their_own_project(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        $project->forceFill(['owner_id' => $admin->id])->save();

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_APPROVED);

        $this->assertSame(Project::STATUS_APPROVED, $project->fresh()->status);
    }

    public function test_any_admin_can_review_not_just_one_designated_admin(): void
    {
        $first = $this->admin('first-admin@example.com');
        $second = $this->admin('second-admin@example.com');

        $own = $this->completeProject(Project::STATUS_SUBMITTED);
        $own->forceFill(['owner_id' => $first->id])->save();

        Sanctum::actingAs($first);

        $this->postJson("/api/v1/projects/{$own->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_APPROVED);

        $theirs = $this->completeProject(Project::STATUS_SUBMITTED);
        $theirs->forceFill(['owner_id' => $first->id])->save();

        Sanctum::actingAs($second);

        $this->postJson("/api/v1/projects/{$theirs->id}/reject", ['reason' => 'Out of scope.'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_REJECTED);

        Sanctum::actingAs($this->admin('third-admin@example.com'));

        $this->getJson('/api/v1/admin/projects')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonFragment(['id' => $theirs->id]);
    }

    public function test_an_invalid_transition_is_refused_by_the_service(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_DRAFT]);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_an_admin_can_create_a_project_and_it_starts_as_a_draft(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/projects', [
            'type' => 'simulation',
            'title' => 'Admin Created Project',
            'description' => 'Created from the admin panel.',
            'capacity' => 6,
            'career_role_id' => $this->careerRole()->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Admin Created Project')
            ->assertJsonPath('data.status', Project::STATUS_DRAFT);

        $this->assertDatabaseHas('projects', [
            'title' => 'Admin Created Project',
            'status' => Project::STATUS_DRAFT,
        ]);
    }

    public function test_creating_refuses_an_unsupported_project_type(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/projects', [
            'type' => 'volunteer',
            'title' => 'Nope',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_creating_requires_a_title(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/projects', ['type' => 'simulation'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_a_guest_cannot_create_a_project(): void
    {
        $this->postJson('/api/v1/admin/projects', [
            'type' => 'simulation',
            'title' => 'Anonymous',
        ])->assertStatus(401);
    }

    public function test_a_non_admin_cannot_create_a_project(): void
    {
        Sanctum::actingAs($this->user('learner@example.com', 'learner'));

        $this->postJson('/api/v1/admin/projects', [
            'type' => 'simulation',
            'title' => 'Sneaky',
        ])->assertStatus(403);
    }

    public function test_an_admin_can_edit_a_draft_project(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_DRAFT, 'title' => 'Before']);

        $this->patchJson("/api/v1/admin/projects/{$project->id}", [
            'title' => 'After',
            'capacity' => 9,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'After')
            ->assertJsonPath('data.capacity', 9);

        $this->assertSame('After', $project->fresh()->title);
    }

    public function test_editing_a_project_that_is_not_editable_is_refused(): void
    {
        Sanctum::actingAs($this->admin());
        $project = $this->project(['status' => Project::STATUS_OPEN, 'title' => 'Locked']);

        $this->patchJson("/api/v1/admin/projects/{$project->id}", ['title' => 'Hijacked'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_EDITABLE');

        $this->assertSame('Locked', $project->fresh()->title);
    }

    public function test_editing_a_missing_project_returns_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->patchJson('/api/v1/admin/projects/999999', ['title' => 'Ghost'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_the_session_create_and_edit_endpoints_are_wired(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin)
            ->postJson('/admin/api/projects', [
                'type' => 'simulation',
                'title' => 'Via Session',
                'capacity' => 3,
                'career_role_id' => $this->careerRole()->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/admin/api/projects/{$created}", ['title' => 'Via Session Edited'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Via Session Edited');
    }
}
