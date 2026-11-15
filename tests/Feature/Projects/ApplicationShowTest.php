<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
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

    private function learner(string $email = 'learner@test.com'): User
    {
        $user = $this->user($email, 'learner');

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function project(User $owner): Project
    {
        return Project::create([
            'organization_id' => $owner->organizations()->first()?->id,
            'owner_id' => $owner->id,
            'title' => 'Source Project',
            'type' => 'company_sponsored',
            'status' => Project::STATUS_OPEN,
            'confidentiality' => 'public',
            'capacity' => 3,
            'version' => 1,
        ]);
    }

    private function application(Project $project, User $applicant, string $status = Application::STATUS_SUBMITTED): Application
    {
        return Application::create([
            'project_id' => $project->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'project_version' => 1,
        ]);
    }

    public function test_the_applicant_can_read_their_own_application(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner();

        $application = $this->application($this->project($owner), $learner);

        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.status', Application::STATUS_SUBMITTED);
    }

    public function test_the_project_owner_can_read_an_application_to_their_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner();

        $application = $this->application($this->project($owner), $learner);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);
    }

    public function test_a_platform_administrator_can_read_any_application(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner();
        $admin = $this->user('admin@test.com', 'admin');

        $application = $this->application($this->project($owner), $learner);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);
    }

    public function test_an_unrelated_learner_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $applicant = $this->learner('applicant@test.com');
        $stranger = $this->learner('stranger@test.com');

        $application = $this->application($this->project($owner), $applicant);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_VIEW_FORBIDDEN');
    }

    public function test_another_organizations_owner_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $otherOwner = $this->user('other@test.com', 'company_admin', $this->organization('Globex'));
        $learner = $this->learner();

        $application = $this->application($this->project($owner), $learner);

        Sanctum::actingAs($otherOwner);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_VIEW_FORBIDDEN');
    }

    public function test_an_unknown_application_answers_404(): void
    {
        $learner = $this->learner();

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/applications/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'APPLICATION_NOT_FOUND');
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson('/api/v1/applications/1')->assertStatus(401);
    }
}
