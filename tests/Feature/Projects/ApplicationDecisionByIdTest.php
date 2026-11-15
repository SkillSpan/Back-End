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

class ApplicationDecisionByIdTest extends TestCase
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

    private function application(
        Project $project,
        User $applicant,
        string $status = Application::STATUS_SUBMITTED,
    ): Application {
        return Application::create([
            'project_id' => $project->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'project_version' => 1,
        ]);
    }

    public function test_the_owner_can_shortlist_by_application_id(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_SHORTLISTED,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.status', Application::STATUS_SHORTLISTED);

        $this->assertSame(
            Application::STATUS_SHORTLISTED,
            $application->fresh()->status,
        );
    }

    public function test_the_owner_can_accept_by_application_id(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_ACCEPTED,
            'reason' => 'Strong portfolio.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_ACCEPTED);
    }

    public function test_the_owner_can_reject_by_application_id(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_REJECTED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_REJECTED);
    }

    public function test_the_owner_can_waitlist_by_application_id(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_WAITLISTED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Application::STATUS_WAITLISTED);
    }

    public function test_both_decision_routes_return_the_same_payload_shape(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        $byProject = $this->application($this->project($owner), $this->learner('a@test.com'));
        $byId = $this->application($this->project($owner), $this->learner('b@test.com'));

        Sanctum::actingAs($owner);

        $scoped = $this->patchJson(
            "/api/v1/projects/{$byProject->project_id}/applications/{$byProject->id}",
            ['status' => Application::STATUS_SHORTLISTED],
        )->assertOk()->json();

        $direct = $this->postJson(
            "/api/v1/applications/{$byId->id}/decision",
            ['status' => Application::STATUS_SHORTLISTED],
        )->assertOk()->json();

        $this->assertSame(array_keys($scoped), array_keys($direct));
        $this->assertSame($scoped['message'], $direct['message']);
        $this->assertSame($scoped['data']['status'], $direct['data']['status']);
    }

    public function test_another_organizations_owner_cannot_decide(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $other = $this->user('other@test.com', 'company_admin', $this->organization('Globex'));
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($other);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_DECISION_FORBIDDEN');

        $this->assertSame(Application::STATUS_SUBMITTED, $application->fresh()->status);
    }

    public function test_the_applicant_cannot_decide_their_own_application(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $applicant = $this->learner();
        $application = $this->application($this->project($owner), $applicant);

        Sanctum::actingAs($applicant);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_DECISION_FORBIDDEN');
    }

    public function test_an_unrelated_learner_cannot_decide(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner('applicant@test.com'));

        Sanctum::actingAs($this->learner('stranger@test.com'));

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_REJECTED,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_DECISION_FORBIDDEN');
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->postJson('/api/v1/applications/1/decision', [
            'status' => Application::STATUS_ACCEPTED,
        ])->assertStatus(401);
    }

    public function test_an_unknown_application_answers_404(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/applications/999999/decision', [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'APPLICATION_NOT_FOUND');
    }

    public function test_a_missing_status_is_a_validation_error(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['code', 'message', 'request_id', 'details' => ['errors' => ['status']]]);
    }

    public function test_a_value_outside_the_four_decision_states_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application($this->project($owner), $this->learner());

        Sanctum::actingAs($owner);

        foreach ([Application::STATUS_SUBMITTED, Application::STATUS_WITHDRAWN, 'shortlist'] as $bad) {
            $this->postJson("/api/v1/applications/{$application->id}/decision", ['status' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_a_transition_the_model_forbids_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $application = $this->application(
            $this->project($owner),
            $this->learner(),
            Application::STATUS_REJECTED,
        );

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/applications/{$application->id}/decision", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_INVALID_TRANSITION');

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
    }
}
