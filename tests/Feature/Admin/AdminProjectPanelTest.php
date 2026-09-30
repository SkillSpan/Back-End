<?php

namespace Tests\Feature\Admin;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Blade projects panel is a session-authenticated page, so these tests
 * assert what the server actually serves: access control, and that the real
 * API endpoints its JavaScript calls are wired and reachable with a session.
 *
 * Client-side rendering is exercised separately (the templates are extracted
 * from this file and run against real API payloads); a page-source assertion
 * cannot prove JavaScript behaviour, so none is attempted here.
 */
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
        // The soft-delete wording must be present, because the backend has no
        // hard delete at all - a "this will erase the project" message would
        // be a lie about what the button does.
        $this->actingAs($this->admin())
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('cancelled', false);
    }

    public function test_the_organizations_page_still_works(): void
    {
        // Regression: adding the projects panel must not disturb the existing
        // panel, its route, or its endpoint.
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/organizations')->assertOk();
        $this->actingAs($admin)->getJson('/admin/api/organizations')->assertOk();
    }

    public function test_the_projects_page_links_back_to_organizations(): void
    {
        // The two management screens must be navigable from each other.
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
}
