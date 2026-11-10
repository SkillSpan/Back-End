<?php

namespace Tests\Feature\Admin;

use App\Models\CareerRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProjectPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Panel Admin',
            'email' => 'panel-admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user->fresh();
    }

    private function project(array $attributes = []): Project
    {
        $owner = $attributes['owner_id'] ?? User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ])->id;

        return Project::create(array_merge([
            'owner_id' => $owner,
            'type' => 'simulation',
            'title' => 'Panel Project',
            'capacity' => 3,
            'status' => Project::STATUS_DRAFT,
            'version' => 1,
        ], $attributes));
    }

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/projects')->assertRedirect('/admin/login');
    }

    public function test_the_panel_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('Projects', false)
            ->assertSee('Add Project', false);
    }

    public function test_the_panel_does_not_offer_a_delete_that_removes_data(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('cancelled', false);
    }

    public function test_the_organizations_page_still_works(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/organizations')->assertOk();
        $this->actingAs($admin)->getJson('/admin/api/organizations')->assertOk();
    }

    public function test_the_projects_page_links_back_to_organizations(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee(route('admin.organizations'), false);
    }

    public function test_the_session_endpoint_lists_projects(): void
    {
        $this->actingAs($this->admin());
        $this->project(['title' => 'Session Listed']);

        $this->getJson('/admin/api/projects')
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'Session Listed');
    }

    public function test_the_session_endpoint_hides_projects_from_a_non_admin(): void
    {
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => 'learner-panel@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $learner->roles()->attach(Role::where('slug', 'learner')->first()->id);

        $this->actingAs($learner->fresh())
            ->getJson('/admin/api/projects')
            ->assertStatus(403);
    }

    public function test_the_panel_skill_picker_has_a_session_backed_endpoint(): void
    {
        Skill::create(['name' => 'PHP', 'slug' => 'php-panel', 'category' => 'backend', 'status' => 'active']);

        $response = $this->actingAs($this->admin())
            ->getJson('/admin/api/skills/taxonomy')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data'])
            ->assertJsonFragment(['name' => 'PHP']);

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_the_skill_picker_endpoint_is_not_public(): void
    {
        $this->getJson('/admin/api/skills/taxonomy')->assertStatus(401);
    }

    public function test_the_project_form_uses_career_role_as_the_source_of_truth(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('id="p-career-role"', false)
            ->assertSee('name="career_role_id"', false)
            ->assertDontSee('id="p-role"', false);
    }

    public function test_project_reference_endpoints_return_only_approved_roles_and_mapped_skills(): void
    {
        $laravel = Skill::create([
            'name' => 'Laravel',
            'slug' => 'laravel-panel-'.uniqid(),
            'category' => 'backend',
            'status' => 'active',
        ]);

        $python = Skill::create([
            'name' => 'Python',
            'slug' => 'python-panel-'.uniqid(),
            'category' => 'data',
            'status' => 'active',
        ]);

        $approved = CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer-panel-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        $pending = CareerRole::create([
            'title' => 'Pending Role',
            'slug' => 'pending-role-panel-'.uniqid(),
            'version' => 1,
            'status' => 'draft',
        ]);

        $approved->roleSkills()->create([
            'skill_id' => $laravel->id,
            'required_level' => 3,
            'importance_weight' => 1,
            'is_critical' => true,
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/admin/api/projects/career-roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $approved->id, 'title' => 'Backend Developer'])
            ->assertJsonMissing(['id' => $pending->id, 'title' => 'Pending Role']);

        $this->actingAs($admin)
            ->getJson("/admin/api/projects/career-roles/{$approved->id}/skills")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $laravel->id, 'name' => 'Laravel'])
            ->assertJsonMissing(['id' => $python->id, 'name' => 'Python']);
    }

    private function completeProject(string $status): Project
    {
        $project = $this->project([
            'status' => $status,
            'description' => 'Described.',
            'objectives' => 'Scoped.',
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
        ]);

        $project->requiredSkills()->create([
            'skill_id' => Skill::create([
                'name' => 'PHP',
                'slug' => 'php-'.uniqid(),
                'category' => 'backend',
                'status' => 'active',
            ])->id,
            'minimum_level' => 3,
            'is_critical_entry' => true,
        ]);

        $project->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);

        return $project->fresh();
    }

    public function test_the_lifecycle_actions_answer_on_the_session_not_a_bearer_token(): void
    {
        $admin = $this->admin();

        $draft = $this->completeProject(Project::STATUS_DRAFT);
        $this->actingAs($admin)
            ->postJson("/admin/api/projects/{$draft->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SUBMITTED);

        $submitted = $this->completeProject(Project::STATUS_SUBMITTED);
        $this->actingAs($admin)
            ->postJson("/admin/api/projects/{$submitted->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_APPROVED);

        $review = $this->completeProject(Project::STATUS_SUBMITTED);
        $this->actingAs($admin)
            ->postJson("/admin/api/projects/{$review->id}/request-changes", ['reason' => 'Needs a budget.'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_CHANGES_REQUESTED);

        $rejectable = $this->completeProject(Project::STATUS_SUBMITTED);
        $this->actingAs($admin)
            ->postJson("/admin/api/projects/{$rejectable->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_REJECTED);

        $approved = $this->completeProject(Project::STATUS_APPROVED);
        $this->actingAs($admin)
            ->postJson("/admin/api/projects/{$approved->id}/open")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_OPEN);
    }

    public function test_the_lifecycle_actions_still_refuse_a_project_that_is_not_ready(): void
    {
        $bare = $this->project(['status' => Project::STATUS_DRAFT]);

        $this->actingAs($this->admin())
            ->postJson("/admin/api/projects/{$bare->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE');
    }

    public function test_the_lifecycle_actions_are_not_public(): void
    {
        $project = $this->completeProject(Project::STATUS_DRAFT);

        $this->postJson("/admin/api/projects/{$project->id}/submit")->assertStatus(401);
    }

    public function test_an_incomplete_project_is_reported_as_a_field_problem_not_a_dead_end(): void
    {
        $bare = $this->project(['status' => Project::STATUS_DRAFT]);

        $this->actingAs($this->admin())
            ->postJson("/admin/api/projects/{$bare->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE')
            ->assertJsonPath('details.missing', fn ($missing) => is_array($missing) && $missing !== []);
    }

    public function test_the_panel_turns_an_incomplete_refusal_into_marked_fields(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/projects');

        $page->assertOk()
            ->assertSee('PROJECT_INCOMPLETE', false)
            ->assertSee('showFormErrors(completenessErrors(missing))', false)
            ->assertSee('focusFirstInvalidField();', false)
            ->assertSee('id="wrap-start_date"', false)
            ->assertSee('id="wrap-end_date"', false)
            ->assertSee('id="wrap-application_deadline"', false)
            ->assertSee('id="wrap-required_skills"', false)
            ->assertSee('id="wrap-roles"', false);
    }

    public function test_the_panel_posts_lifecycle_actions_to_its_own_session_prefix(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('/admin/api/projects/${id}/${meta.path}', false)
            ->assertDontSee('api(`/api/v1/projects', false);
    }
}
