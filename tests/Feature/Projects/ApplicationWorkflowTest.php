<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectRole;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectCapacityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-MATCH-02 — project application workflow.
 *
 * Covers the runtime layer end to end: submission, every validation failure,
 * capacity, deadline, eligibility, duplicate, idempotency, withdrawal, owner
 * decisions, listing, audit rows and notifications.
 *
 * Only the capacity/eligibility rules that ACTUALLY exist are asserted; where
 * the business rule is still a config default (which statuses consume a seat)
 * the test asserts the DEFAULT's behaviour and says so.
 */
class ApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(): Organization
    {
        return Organization::first() ?? Organization::create(['name' => 'Test Org', 'type' => 'company']);
    }

    private function createUser(string $email, ?string $role = 'learner', bool $withProfile = true): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        if ($role !== null) {
            $user->roles()->attach(Role::where('slug', $role)->first()->id);
        }

        $user->organizations()->attach($this->organization()->id, [
            'role_in_org' => 'member',
            'status' => 'active',
        ]);

        if ($withProfile) {
            StudentProfile::forceCreate([
                'user_id' => $user->id,
                'visibility' => 'private',
                'consent_given' => true,
            ]);
        }

        return $user->fresh();
    }

    private function createProject(array $attributes = []): Project
    {
        // Reuse the default owner across calls: several tests create more than
        // one project, and forceCreate would collide on the users.email unique
        // index if the same owner were inserted twice.
        $owner = $attributes['owner_id']
            ?? User::where('email', 'owner@test.com')->value('id')
            ?? $this->createUser('owner@test.com', null)->id;

        return Project::create(array_merge([
            'organization_id' => $this->organization()->id,
            'owner_id' => $owner,
            'title' => 'Test Project',
            'description' => 'A test project',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'capacity' => 3,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ], $attributes));
    }

    private function addSkillEvaluation(User $learner, Skill $skill, float $level): SkillEvaluation
    {
        return SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $skill->id,
            'level' => $level,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);
    }

    private function acceptAnotherLearner(Project $project, string $email = 'other@test.com'): Application
    {
        $other = $this->createUser($email, 'learner', false);

        $application = new Application([
            'project_id' => $project->id,
            'applicant_id' => $other->id,
        ]);
        $application->status = Application::STATUS_ACCEPTED;
        $application->save();

        return $application;
    }

    private function submit(Project $project, array $payload = [])
    {
        return $this->postJson("/api/v1/projects/{$project->id}/applications", $payload);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_submission_is_rejected(): void
    {
        $project = $this->createProject();

        $this->submit($project)->assertStatus(401);
    }

    public function test_non_learner_cannot_submit_an_application(): void
    {
        $admin = $this->createUser('admin@test.com', 'admin');
        Sanctum::actingAs($admin);

        $project = $this->createProject();

        $this->submit($project)->assertStatus(403);
    }

    public function test_learner_without_a_student_profile_gets_a_clear_error(): void
    {
        $learner = $this->createUser('noprofile@test.com', 'learner', false);
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }

    public function test_missing_project_returns_404(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/projects/999999/applications')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_learner_can_submit_an_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $response = $this->submit($project);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Application::STATUS_SUBMITTED)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.applicant_id', $learner->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.project_version', 1);

        $this->assertNotNull($response->json('request_id'));

        $this->assertDatabaseHas('applications', [
            'project_id' => $project->id,
            'applicant_id' => $learner->id,
            'status' => Application::STATUS_SUBMITTED,
            'active_key' => 1,
        ]);
    }

    public function test_submitted_at_is_set_automatically(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project)->assertStatus(201);

        $application = Application::where('applicant_id', $learner->id)->firstOrFail();

        $this->assertNotNull($application->submitted_at);
        $this->assertNull($application->withdrawn_at);
    }

    public function test_application_data_round_trips(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project, [
            'application_data' => ['motivation' => 'I want to build things', 'hours' => 12],
        ])->assertStatus(201)
            ->assertJsonPath('data.application_data.motivation', 'I want to build things')
            ->assertJsonPath('data.application_data.hours', 12);
    }

    // -----------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------

    public function test_cannot_apply_to_a_project_that_is_not_open(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['status' => 'closed']);

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_AVAILABLE');
    }

    public function test_cannot_apply_after_the_deadline(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['application_deadline' => now()->subDay()->toDateString()]);

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_AVAILABLE');
    }

    // -----------------------------------------------------------------
    // Project roles
    // -----------------------------------------------------------------

    public function test_a_valid_project_role_is_recorded_on_the_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $role = ProjectRole::create(['project_id' => $project->id, 'title' => 'Backend Developer']);

        $this->submit($project, ['project_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.project_role_id', $role->id);
    }

    public function test_a_role_from_another_project_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $otherProject = $this->createProject(['title' => 'Other Project']);
        $foreignRole = ProjectRole::create(['project_id' => $otherProject->id, 'title' => 'Designer']);

        $this->submit($project, ['project_role_id' => $foreignRole->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ROLE_INVALID');
    }

    public function test_an_inactive_project_role_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $role = ProjectRole::create([
            'project_id' => $project->id,
            'title' => 'Retired Role',
            'is_active' => false,
        ]);

        $this->submit($project, ['project_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ROLE_INACTIVE');
    }

    // -----------------------------------------------------------------
    // Duplicate
    // -----------------------------------------------------------------

    public function test_a_second_active_application_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project)->assertStatus(201);
        $this->submit($project)
            ->assertStatus(409)
            ->assertJsonPath('code', 'APPLICATION_DUPLICATE');

        $this->assertSame(1, Application::where('applicant_id', $learner->id)->count());
    }

    public function test_learner_can_reapply_after_withdrawing(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $first = $this->submit($project)->assertStatus(201);
        $applicationId = $first->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/withdraw")->assertStatus(200);

        // The withdrawal released the unique slot, so a new application is allowed.
        $this->submit($project)->assertStatus(201);

        $this->assertSame(2, Application::where('applicant_id', $learner->id)->count());
    }

    // -----------------------------------------------------------------
    // Capacity
    // -----------------------------------------------------------------

    public function test_a_full_project_rejects_a_new_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 1]);
        $this->acceptAnotherLearner($project);

        $this->submit($project)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROJECT_FULL')
            ->assertJsonPath('details.seats_remaining', 0);
    }

    public function test_a_submitted_application_does_not_consume_a_seat(): void
    {
        // Asserts the DEFAULT policy: only `accepted` occupies a seat. Changing
        // config/project_application.php changes this behaviour with no code edit.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 1]);

        $other = $this->createUser('other@test.com', 'learner', false);
        $pending = new Application(['project_id' => $project->id, 'applicant_id' => $other->id]);
        $pending->status = Application::STATUS_SUBMITTED;
        $pending->save();

        // The other learner only applied, so a seat is still free.
        $this->submit($project)->assertStatus(201);
    }

    public function test_capacity_of_null_is_treated_as_unlimited_by_the_policy(): void
    {
        $policy = new ProjectCapacityPolicy;

        $project = new Project(['capacity' => null]);

        $this->assertNull($policy->totalSeats($project));
        $this->assertNull($policy->seatsRemaining($project));
        $this->assertFalse($policy->isFull($project));
        $this->assertFalse($policy->blocksNewApplication($project));
    }

    public function test_capacity_is_not_used_as_an_eligibility_mechanism(): void
    {
        // A project with seats left must never be blocked because of capacity,
        // regardless of the learner's skills — capacity is a seat count only.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 10]);

        $this->submit($project)->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------

    public function test_ineligible_learner_is_rejected_with_skill_failures(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 2.0);

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_NOT_ELIGIBLE')
            ->assertJsonPath('details.skill_failures.0.skill_id', $skill->id);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_eligible_learner_passes_the_critical_skill_check(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 4.0);

        $this->submit($project)->assertStatus(201);
    }

    public function test_a_mismatched_work_mode_constraint_blocks_the_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $learner->studentProfile->update(['preferred_work_type' => 'remote']);

        $project = $this->createProject();

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'work_mode',
            'value' => 'onsite',
        ]);

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_NOT_ELIGIBLE');
    }

    public function test_an_active_team_assignment_blocks_the_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $team = ProjectTeam::create(['project_id' => $project->id, 'name' => 'Team Alpha']);

        ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $learner->id,
            'assignment_state' => 'active',
        ]);

        $this->submit($project)
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_NOT_ELIGIBLE');
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    public function test_application_data_must_be_structured_data(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project, ['application_data' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_idempotency_key_longer_than_191_chars_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project, ['idempotency_key' => str_repeat('a', 192)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_replaying_an_idempotency_key_returns_the_original_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $first = $this->submit($project, ['idempotency_key' => 'key-1'])->assertStatus(201);
        $second = $this->submit($project, ['idempotency_key' => 'key-1'])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Application::where('applicant_id', $learner->id)->count());
    }

    public function test_reusing_a_key_with_a_different_body_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project, [
            'idempotency_key' => 'key-2',
            'application_data' => ['note' => 'first'],
        ])->assertStatus(201);

        $this->submit($project, [
            'idempotency_key' => 'key-2',
            'application_data' => ['note' => 'different'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_IDEMPOTENCY_CONFLICT');
    }

    public function test_an_idempotency_replay_tolerates_reordered_application_data_keys(): void
    {
        // Same logical body, but the nested keys serialized in a different order.
        // A client that builds the object from an unordered map can legitimately
        // produce this on a retry, so it must REPLAY, not report a conflict.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $first = $this->submit($project, [
            'idempotency_key' => 'order-key',
            'application_data' => ['alpha' => 1, 'beta' => 2],
        ])->assertStatus(201);

        $second = $this->submit($project, [
            'idempotency_key' => 'order-key',
            'application_data' => ['beta' => 2, 'alpha' => 1],
        ])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Application::where('applicant_id', $learner->id)->count());
    }

    public function test_an_idempotency_replay_tolerates_reordered_nested_keys(): void
    {
        // The same guarantee one level deeper: nested objects inside
        // application_data must normalize too, not just the top level.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $first = $this->submit($project, [
            'idempotency_key' => 'nested-key',
            'application_data' => ['answers' => ['x' => 1, 'y' => 2], 'note' => 'hi'],
        ])->assertStatus(201);

        $second = $this->submit($project, [
            'idempotency_key' => 'nested-key',
            'application_data' => ['note' => 'hi', 'answers' => ['y' => 2, 'x' => 1]],
        ])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_an_idempotency_replay_still_detects_a_genuinely_different_body(): void
    {
        // Guards the fix: normalizing must not make DIFFERENT bodies look equal.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $this->submit($project, [
            'idempotency_key' => 'real-diff-key',
            'application_data' => ['alpha' => 1, 'beta' => 2],
        ])->assertStatus(201);

        $this->submit($project, [
            'idempotency_key' => 'real-diff-key',
            'application_data' => ['alpha' => 1, 'beta' => 3],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_IDEMPOTENCY_CONFLICT');
    }

    public function test_an_idempotent_replay_does_not_re_run_capacity_checks(): void
    {
        // A retry must return the original result even if the project filled up
        // in the meantime, otherwise a network retry would turn a success into
        // a failure.
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 2]);

        $this->submit($project, ['idempotency_key' => 'key-3'])->assertStatus(201);

        // Fill the project completely.
        $this->acceptAnotherLearner($project, 'other1@test.com');
        $this->acceptAnotherLearner($project, 'other2@test.com');

        $this->submit($project, ['idempotency_key' => 'key-3'])->assertStatus(200);
    }

    public function test_applications_without_an_idempotency_key_do_not_collide(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $projectA = $this->createProject(['title' => 'A']);
        $projectB = $this->createProject(['title' => 'B']);

        $this->submit($projectA)->assertStatus(201);
        $this->submit($projectB)->assertStatus(201);

        $this->assertSame(2, Application::where('applicant_id', $learner->id)->count());
    }

    // -----------------------------------------------------------------
    // Recommendation linkage
    // -----------------------------------------------------------------

    public function test_recommendation_versions_are_stored_on_the_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $recommendation = Recommendation::create([
            'user_id' => $learner->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'algorithm_version' => 'project-match-v1',
            'configuration_version' => 'config-v1',
            'generated_at' => now(),
        ]);

        $this->submit($project, ['recommendation_id' => $recommendation->id])
            ->assertStatus(201)
            ->assertJsonPath('data.recommendation_id', $recommendation->id)
            ->assertJsonPath('data.recommendation_algorithm_version', 'project-match-v1')
            ->assertJsonPath('data.recommendation_configuration_version', 'config-v1');
    }

    public function test_another_learners_recommendation_cannot_be_referenced(): void
    {
        $learner = $this->createUser('learner@test.com');
        $other = $this->createUser('other@test.com', 'learner', false);
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $foreign = Recommendation::create([
            'user_id' => $other->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'generated_at' => now(),
        ]);

        $this->submit($project, ['recommendation_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECOMMENDATION_NOT_FOUND');
    }

    public function test_a_recommendation_pointing_at_another_project_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $otherProject = $this->createProject(['title' => 'Elsewhere']);

        $recommendation = Recommendation::create([
            'user_id' => $learner->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $otherProject->id,
            'generated_at' => now(),
        ]);

        $this->submit($project, ['recommendation_id' => $recommendation->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECOMMENDATION_PROJECT_MISMATCH');
    }

    // -----------------------------------------------------------------
    // Audit + notification
    // -----------------------------------------------------------------

    public function test_submission_writes_an_audit_row(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $response = $this->submit($project)->assertStatus(201);
        $applicationId = $response->json('data.id');

        $audit = AuditEvent::where('action', 'application.submitted')
            ->where('entity_id', $applicationId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($learner->id, (int) $audit->actor_id);
        $this->assertSame('application', $audit->entity_type);
        $this->assertSame($project->id, (int) $audit->after['project_id']);
        $this->assertSame('project_application', $audit->purpose);
        $this->assertNotNull($audit->request_id);
    }

    public function test_the_project_owner_is_notified_of_a_new_application(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['owner_id' => $owner->id]);

        $response = $this->submit($project)->assertStatus(201);

        $notification = Notification::where('user_id', $owner->id)
            ->where('category', 'application')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(
            'application.submitted:'.$response->json('data.id'),
            $notification->event_key,
        );
    }

    public function test_a_retry_does_not_notify_the_owner_twice(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['owner_id' => $owner->id]);

        $this->submit($project, ['idempotency_key' => 'dup-key'])->assertStatus(201);
        $this->submit($project, ['idempotency_key' => 'dup-key'])->assertStatus(200);

        $this->assertSame(1, Notification::where('user_id', $owner->id)->count());
    }

    // -----------------------------------------------------------------
    // Withdrawal
    // -----------------------------------------------------------------

    public function test_learner_can_withdraw_their_own_application(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();
        $applicationId = $this->submit($project)->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/withdraw")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Application::STATUS_WITHDRAWN)
            ->assertJsonPath('data.is_active', false);

        $application = Application::find($applicationId);

        $this->assertNotNull($application->withdrawn_at);
        $this->assertNull($application->active_key);
    }

    public function test_a_learner_cannot_withdraw_someone_elses_application(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $victim = $this->createUser('victim@test.com', 'learner', false);
        $attacker = $this->createUser('attacker@test.com');
        Sanctum::actingAs($attacker);

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $victim->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        $this->postJson("/api/v1/applications/{$application->id}/withdraw")
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_NOT_OWNED');

        $this->assertSame(Application::STATUS_SUBMITTED, Application::find($application->id)->status);
    }

    public function test_a_rejected_application_cannot_be_withdrawn(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject();

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_REJECTED;
        $application->save();

        $this->postJson("/api/v1/applications/{$application->id}/withdraw")
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_NOT_WITHDRAWABLE');
    }

    public function test_withdrawing_a_missing_application_returns_404(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/applications/999999/withdraw')
            ->assertStatus(404)
            ->assertJsonPath('code', 'APPLICATION_NOT_FOUND');
    }

    public function test_withdrawing_an_accepted_application_releases_the_seat(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $project = $this->createProject(['capacity' => 1]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_ACCEPTED;
        $application->save();

        $this->postJson("/api/v1/applications/{$application->id}/withdraw")->assertStatus(200);

        $policy = new ProjectCapacityPolicy;
        $this->assertFalse($policy->isFull($project->fresh()));
    }

    // -----------------------------------------------------------------
    // Owner decisions
    // -----------------------------------------------------------------

    public function test_the_project_owner_can_accept_an_application(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
            'reason' => 'Strong fit.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Application::STATUS_ACCEPTED)
            ->assertJsonPath('data.decision_reason', 'Strong fit.');
    }

    public function test_a_non_owner_cannot_decide_on_an_application(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');
        $intruder = $this->createUser('intruder@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_DECISION_FORBIDDEN');
    }

    public function test_an_invalid_status_transition_is_rejected(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_REJECTED;
        $application->save();

        Sanctum::actingAs($owner);

        // rejected is terminal: nothing may move out of it.
        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_INVALID_TRANSITION');
    }

    public function test_accepting_into_a_full_project_is_rejected(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id, 'capacity' => 1]);
        $this->acceptAnotherLearner($project);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROJECT_FULL');
    }

    public function test_a_decision_notifies_the_applicant(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $learner->id,
            'category' => 'application',
            'event_key' => 'application.status:'.$application->id.':accepted',
        ]);
    }

    public function test_an_application_from_another_project_is_rejected(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);
        $otherProject = $this->createProject(['title' => 'Other', 'owner_id' => $owner->id]);

        $application = new Application(['project_id' => $otherProject->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/projects/{$project->id}/applications/{$application->id}", [
            'status' => Application::STATUS_ACCEPTED,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'APPLICATION_PROJECT_MISMATCH');
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function test_learner_sees_only_their_own_applications(): void
    {
        $learner = $this->createUser('learner@test.com');
        $other = $this->createUser('other@test.com', 'learner', false);

        $project = $this->createProject();

        $mine = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $mine->status = Application::STATUS_SUBMITTED;
        $mine->save();

        $theirs = new Application(['project_id' => $project->id, 'applicant_id' => $other->id]);
        $theirs->status = Application::STATUS_SUBMITTED;
        $theirs->save();

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/applications')->assertStatus(200);

        $this->assertSame(1, count($response->json('data')));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_application_list_can_be_filtered_by_status(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $projectA = $this->createProject(['title' => 'A']);
        $projectB = $this->createProject(['title' => 'B']);

        $this->submit($projectA)->assertStatus(201);

        $withdrawn = new Application(['project_id' => $projectB->id, 'applicant_id' => $learner->id]);
        $withdrawn->status = Application::STATUS_WITHDRAWN;
        $withdrawn->save();

        $response = $this->getJson('/api/v1/applications?status=withdrawn')->assertStatus(200);

        $this->assertSame(1, count($response->json('data')));
        $this->assertSame(Application::STATUS_WITHDRAWN, $response->json('data.0.status'));
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/applications?status=banana')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_application_list_is_paginated(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $this->submit($this->createProject(['title' => $title]))->assertStatus(201);
        }

        $response = $this->getJson('/api/v1/applications?per_page=2')->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(2, $response->json('meta.per_page'));
    }

    public function test_per_page_is_clamped_to_the_maximum(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $this->submit($this->createProject())->assertStatus(201);

        $this->getJson('/api/v1/applications?per_page=9999')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_an_invalid_per_page_falls_back_to_the_default(): void
    {
        $learner = $this->createUser('learner@test.com');
        Sanctum::actingAs($learner);

        $this->submit($this->createProject())->assertStatus(201);

        $this->getJson('/api/v1/applications?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_the_owner_application_list_is_paginated(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $project = $this->createProject(['owner_id' => $owner->id]);

        foreach (['first@test.com', 'second@test.com'] as $email) {
            $applicant = $this->createUser($email, 'learner', false);
            $application = new Application(['project_id' => $project->id, 'applicant_id' => $applicant->id]);
            $application->status = Application::STATUS_SUBMITTED;
            $application->save();
        }

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/projects/{$project->id}/applications?per_page=1")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_the_project_owner_can_list_applications_for_their_project(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $learner = $this->createUser('learner@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        $application = new Application(['project_id' => $project->id, 'applicant_id' => $learner->id]);
        $application->status = Application::STATUS_SUBMITTED;
        $application->save();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/projects/{$project->id}/applications")->assertStatus(200);

        $this->assertSame(1, count($response->json('data')));
        $this->assertSame($application->id, $response->json('data.0.id'));
    }

    public function test_a_non_owner_cannot_list_a_projects_applications(): void
    {
        $owner = $this->createUser('owner@test.com', null);
        $intruder = $this->createUser('intruder@test.com');

        $project = $this->createProject(['owner_id' => $owner->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/projects/{$project->id}/applications")
            ->assertStatus(403)
            ->assertJsonPath('code', 'APPLICATION_LIST_FORBIDDEN');
    }
}
