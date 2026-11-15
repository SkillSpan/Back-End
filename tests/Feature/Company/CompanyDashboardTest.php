<?php

namespace Tests\Feature\Company;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function organization(string $name = 'Acme', string $status = 'verified', array $attributes = []): Organization
    {
        return Organization::forceCreate(array_merge([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ], $attributes));
    }

    private function companyAdmin(?Organization $organization = null, string $organizationStatus = 'verified', string $roleInOrg = 'admin'): User
    {
        $organization ??= $this->organization('Acme', $organizationStatus);

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

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function project(Organization $organization, array $attributes = []): Project
    {
        return Project::forceCreate(array_merge([
            'organization_id' => $organization->id,
            'owner_id' => $this->owner()->id,
            'title' => 'Project '.uniqid(),
            'type' => 'company_sponsored',
            'domain' => 'Backend',
            'status' => Project::STATUS_OPEN,
            'capacity' => 3,
            'application_deadline' => now()->addDays(30)->toDateString(),
        ], $attributes));
    }

    private function owner(): User
    {
        return $this->owner ??= User::forceCreate([
            'name' => 'Project Owner',
            'email' => uniqid('owner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function application(Project $project, string $status): Application
    {
        return Application::forceCreate([
            'project_id' => $project->id,
            'applicant_id' => $this->learner()->id,
            'status' => $status,
        ]);
    }

    private function notification(User $user, bool $read = false): Notification
    {
        return Notification::forceCreate([
            'user_id' => $user->id,
            'category' => 'project',
            'channel' => 'in_app',
            'title' => 'Something happened',
            'body' => 'Body',
            'event_key' => uniqid('evt-'),
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_company_admin_of_an_approved_organization_can_load_the_dashboard(): void
    {
        $admin = $this->companyAdmin();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'counts' => ['projects', 'applicationsPending', 'unreadNotifications'],
                    'permissions' => ['can_publish_projects', 'can_view_projects', 'can_review_applications', 'can_manage_profile'],
                    'tasks',
                    'projectStates',
                    'recentProjects',
                    'funnel' => ['total', 'stages', 'byProject'],
                ],
            ]);
    }

    public function test_a_learner_is_refused(): void
    {
        Sanctum::actingAs($this->learner());

        $this->getJson('/api/v1/company/dashboard')->assertForbidden();
    }

    public function test_a_member_who_is_not_an_admin_is_refused_with_a_code(): void
    {
        $member = $this->companyAdmin(roleInOrg: 'member');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/company/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'NOT_ORGANIZATION_ADMIN');
    }

    public function test_a_pending_organization_is_refused_with_a_pending_code(): void
    {
        $admin = $this->companyAdmin(organizationStatus: 'pending');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }

    public function test_a_rejected_organization_is_refused_with_its_own_code(): void
    {
        $admin = $this->companyAdmin(organizationStatus: 'rejected');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_NOT_APPROVED');
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson('/api/v1/company/dashboard')->assertUnauthorized();
    }

    public function test_another_organizations_projects_and_applications_never_appear(): void
    {
        $mine = $this->organization('Mine');
        $theirs = $this->organization('Theirs');

        $admin = $this->companyAdmin($mine);

        $myProject = $this->project($mine, ['title' => 'My Project']);
        $theirProject = $this->project($theirs, ['title' => 'Their Project']);

        $this->application($myProject, Application::STATUS_SUBMITTED);
        $this->application($theirProject, Application::STATUS_SUBMITTED);
        $this->application($theirProject, Application::STATUS_ACCEPTED);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/dashboard')->assertOk();

        $this->assertSame(1, $response->json('data.counts.projects'));
        $this->assertSame(1, $response->json('data.funnel.total'));
        $this->assertSame(1, $response->json('data.projectStates.open'));

        $names = array_column($response->json('data.recentProjects'), 'name');
        $this->assertContains('My Project', $names);
        $this->assertNotContains('Their Project', $names);

        $funnelProjects = array_column($response->json('data.funnel.byProject'), 'name');
        $this->assertNotContains('Their Project', $funnelProjects);
    }

    public function test_an_admin_of_one_organization_reads_that_one_not_another_they_belong_to(): void
    {
        $administered = $this->organization('Administered');
        $memberOf = $this->organization('Member Of');

        $admin = $this->companyAdmin($administered);

        OrganizationMember::forceCreate([
            'organization_id' => $memberOf->id,
            'user_id' => $admin->id,
            'role_in_org' => 'member',
            'status' => 'active',
        ]);

        $this->project($administered, ['title' => 'Administered Project']);
        $this->project($memberOf, ['title' => 'Member Project']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/dashboard')->assertOk();

        $this->assertSame(1, $response->json('data.counts.projects'));
        $this->assertSame(['Administered Project'], array_column($response->json('data.recentProjects'), 'name'));
    }

    public function test_project_counts_cover_every_lifecycle_state_including_zeros(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $this->project($organization, ['status' => Project::STATUS_DRAFT]);
        $this->project($organization, ['status' => Project::STATUS_DRAFT]);
        $this->project($organization, ['status' => Project::STATUS_OPEN]);
        $this->project($organization, ['status' => Project::STATUS_CHANGES_REQUESTED]);
        $this->project($organization, ['status' => Project::STATUS_COMPLETED]);

        Sanctum::actingAs($admin);

        $states = $this->getJson('/api/v1/company/dashboard')->json('data.projectStates');

        foreach (Project::STATUSES as $status) {
            $this->assertArrayHasKey($status, $states);
        }

        $this->assertSame(2, $states[Project::STATUS_DRAFT]);
        $this->assertSame(1, $states[Project::STATUS_OPEN]);
        $this->assertSame(1, $states[Project::STATUS_CHANGES_REQUESTED]);
        $this->assertSame(1, $states[Project::STATUS_COMPLETED]);
        $this->assertSame(0, $states[Project::STATUS_SELECTION]);
        $this->assertSame(5, array_sum($states));
    }

    public function test_recent_projects_report_capacity_and_a_closed_deadline(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $project = $this->project($organization, [
            'title' => 'Closing Soon',
            'capacity' => 4,
            'application_deadline' => now()->subDay()->toDateString(),
            'type' => 'simulation',
            'domain' => 'Data',
        ]);

        $this->application($project, Application::STATUS_ACCEPTED);
        $this->application($project, Application::STATUS_ACCEPTED);
        $this->application($project, Application::STATUS_WITHDRAWN);

        Sanctum::actingAs($admin);

        $row = $this->getJson('/api/v1/company/dashboard')->json('data.recentProjects.0');

        $this->assertSame('Closing Soon', $row['name']);
        $this->assertSame('simulated', $row['kind']);
        $this->assertSame('Data', $row['track']);
        $this->assertSame(2, $row['filled']);
        $this->assertSame(4, $row['capacity']);
        $this->assertTrue($row['deadline']['closed']);
    }

    public function test_current_tasks_report_each_actionable_state(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $revise = $this->project($organization, ['title' => 'Needs Revision', 'status' => Project::STATUS_CHANGES_REQUESTED]);
        $this->project($organization, ['title' => 'Still A Draft', 'status' => Project::STATUS_DRAFT]);
        $closing = $this->project($organization, [
            'title' => 'Closing In Three Days',
            'status' => Project::STATUS_OPEN,
            'application_deadline' => now()->addDays(3)->toDateString(),
        ]);
        $selection = $this->project($organization, [
            'title' => 'Filling Seats',
            'status' => Project::STATUS_SELECTION,
            'capacity' => 3,
        ]);

        $this->application($revise, Application::STATUS_SUBMITTED);
        $this->application($closing, Application::STATUS_SUBMITTED);
        $this->application($selection, Application::STATUS_ACCEPTED);

        Sanctum::actingAs($admin);

        $tasks = collect($this->getJson('/api/v1/company/dashboard')->json('data.tasks'));

        $review = $tasks->firstWhere('type', 'review');
        $this->assertNotNull($review);
        $this->assertSame(2, $review['count']);
        $this->assertSame('Needs Revision', $review['projects'][0]['name']);

        $this->assertSame('Needs Revision', $tasks->firstWhere('type', 'revise')['project']);

        $deadline = $tasks->firstWhere('type', 'deadline');
        $this->assertSame('Closing In Three Days', $deadline['project']);
        $this->assertSame(3, $deadline['days']);
        $this->assertSame(1, $deadline['applications']);

        $roles = $tasks->firstWhere('type', 'roles');
        $this->assertSame('Filling Seats', $roles['project']);
        $this->assertSame(1, $roles['filled']);
        $this->assertSame(3, $roles['total']);
        $this->assertSame(2, $roles['open']);

        $this->assertSame('Still A Draft', $tasks->firstWhere('type', 'draft')['project']);
    }

    public function test_a_full_selection_project_produces_no_roles_task(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $project = $this->project($organization, [
            'status' => Project::STATUS_SELECTION,
            'capacity' => 1,
        ]);
        $this->application($project, Application::STATUS_ACCEPTED);

        Sanctum::actingAs($admin);

        $tasks = collect($this->getJson('/api/v1/company/dashboard')->json('data.tasks'));

        $this->assertNull($tasks->firstWhere('type', 'roles'));
    }

    public function test_a_deadline_outside_the_window_is_not_a_task(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $this->project($organization, [
            'status' => Project::STATUS_OPEN,
            'application_deadline' => now()->addDays(60)->toDateString(),
        ]);

        Sanctum::actingAs($admin);

        $tasks = collect($this->getJson('/api/v1/company/dashboard')->json('data.tasks'));

        $this->assertNull($tasks->firstWhere('type', 'deadline'));
    }

    public function test_the_funnel_counts_each_status_and_totals_correctly(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $project = $this->project($organization, ['application_deadline' => now()->addDays(10)->toDateString()]);

        $this->application($project, Application::STATUS_SUBMITTED);
        $this->application($project, Application::STATUS_SUBMITTED);
        $this->application($project, Application::STATUS_SHORTLISTED);
        $this->application($project, Application::STATUS_ACCEPTED);
        $this->application($project, Application::STATUS_REJECTED);
        $this->application($project, Application::STATUS_WITHDRAWN);

        Sanctum::actingAs($admin);

        $funnel = $this->getJson('/api/v1/company/dashboard')->json('data.funnel');

        $this->assertSame(6, $funnel['total']);
        $this->assertSame(2, $funnel['stages']['submitted']);
        $this->assertSame(1, $funnel['stages']['shortlisted']);
        $this->assertSame(1, $funnel['stages']['accepted']);
        $this->assertSame(1, $funnel['stages']['rejected']);
        $this->assertSame(1, $funnel['stages']['withdrawn']);
        $this->assertSame(0, $funnel['stages']['expired']);

        $this->assertSame($funnel['total'], array_sum($funnel['stages']));
    }

    public function test_undecided_applications_on_a_closed_project_are_reported_as_expired(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $closed = $this->project($organization, ['application_deadline' => now()->subDays(5)->toDateString()]);
        $live = $this->project($organization, ['application_deadline' => now()->addDays(5)->toDateString()]);

        $this->application($closed, Application::STATUS_SUBMITTED);
        $this->application($closed, Application::STATUS_SUBMITTED);
        $this->application($live, Application::STATUS_SUBMITTED);

        Sanctum::actingAs($admin);

        $funnel = $this->getJson('/api/v1/company/dashboard')->json('data.funnel');

        $this->assertSame(2, $funnel['stages']['expired']);
        $this->assertSame(1, $funnel['stages']['submitted']);
        $this->assertSame(3, $funnel['total']);
        $this->assertSame($funnel['total'], array_sum($funnel['stages']));
    }

    public function test_the_funnel_never_exposes_applicant_identity(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $project = $this->project($organization);

        $applicant = $this->learner();
        Application::forceCreate([
            'project_id' => $project->id,
            'applicant_id' => $applicant->id,
            'status' => Application::STATUS_SUBMITTED,
        ]);

        Sanctum::actingAs($admin);

        $payload = json_encode($this->getJson('/api/v1/company/dashboard')->json('data.funnel'));

        $this->assertStringNotContainsString($applicant->email, $payload);
        $this->assertStringNotContainsString($applicant->name, $payload);
        $this->assertStringNotContainsString('applicant', $payload);
    }

    public function test_a_new_organization_gets_zero_counts_and_an_empty_dashboard(): void
    {
        $organization = $this->organization('Brand New');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/company/dashboard')->assertOk();

        $this->assertSame(0, $response->json('data.counts.projects'));
        $this->assertSame(0, $response->json('data.counts.applicationsPending'));
        $this->assertSame([], $response->json('data.tasks'));
        $this->assertSame([], $response->json('data.recentProjects'));
        $this->assertSame(0, $response->json('data.funnel.total'));
        $this->assertSame(0, array_sum($response->json('data.projectStates')));
    }

    public function test_unread_notifications_are_counted_for_the_caller_only(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);
        $someoneElse = $this->learner();

        $this->notification($admin);
        $this->notification($admin);
        $this->notification($admin, read: true);
        $this->notification($someoneElse);

        Sanctum::actingAs($admin);

        $this->assertSame(2, $this->getJson('/api/v1/company/dashboard')->json('data.counts.unreadNotifications'));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_projects(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        foreach ($this->states() as $index => $status) {
            $this->seedProject($organization, $status, $index);
        }

        Sanctum::actingAs($admin);

        $small = $this->countQueries();

        foreach (range(1, 20) as $i) {
            $this->seedProject($organization, $this->states()[$i % 4], $i % 5);
        }

        $large = $this->countQueries();

        $this->assertSame(
            $small,
            $large,
            "The dashboard ran {$small} queries for 4 projects and {$large} for 24 — the count must not follow the row count.",
        );
    }

    public function test_the_whole_request_costs_a_bounded_number_of_queries(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        foreach ($this->states() as $index => $status) {
            $this->seedProject($organization, $status, $index);
        }

        Sanctum::actingAs($admin);

        $this->assertLessThanOrEqual(
            20,
            $this->countQueries(),
            'The dashboard request exceeded its query budget.',
        );
    }

    private function states(): array
    {
        return [
            Project::STATUS_DRAFT,
            Project::STATUS_CHANGES_REQUESTED,
            Project::STATUS_SELECTION,
            Project::STATUS_OPEN,
        ];
    }

    private function seedProject(Organization $organization, string $status, int $dayOffset): Project
    {
        $project = $this->project($organization, [
            'status' => $status,
            'application_deadline' => now()->addDays($dayOffset)->toDateString(),
        ]);

        $this->application($project, Application::STATUS_SUBMITTED);
        $this->application($project, Application::STATUS_ACCEPTED);

        return $project;
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/company/dashboard')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_a_denied_dashboard_access_is_recorded_as_an_audit_event(): void
    {
        $member = $this->companyAdmin(roleInOrg: 'member');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/company/dashboard')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $member->id,
            'action' => 'company.dashboard.denied',
            'entity_type' => 'organization',
        ]);
    }

    public function test_a_pending_organization_refusal_is_recorded_as_an_audit_event(): void
    {
        $organization = $this->organization('Pending Co', 'pending');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/dashboard')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'organization.access_denied',
            'entity_id' => $organization->id,
        ]);
    }

    public function test_a_company_login_states_the_dashboard_as_its_destination(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $this->postJson('/api/v1/auth/login/organization', [
            'email' => $admin->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect_url', '/company/dashboard')
            ->assertJsonPath('data.organizations.0.id', $organization->id);
    }
}
