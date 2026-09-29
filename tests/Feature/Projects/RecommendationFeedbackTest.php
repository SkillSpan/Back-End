<?php

namespace Tests\Feature\Projects;

use App\Models\FeedbackEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-MATCH-02 — recommendation feedback (save / hide / decline).
 *
 * Asserts that feedback still lands in the EXISTING feedback_events table
 * through the existing FeedbackEvent model, and that `decline` maps onto the
 * pre-existing `reject` event rather than a parallel concept.
 */
class RecommendationFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createLearner(string $email = 'learner@test.com'): User
    {
        $org = Organization::create(['name' => 'Test Org', 'type' => 'company']);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);
        $user->organizations()->attach($org->id, ['role_in_org' => 'member', 'status' => 'active']);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function createProject(): Project
    {
        $owner = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return Project::create([
            'organization_id' => Organization::first()->id,
            'owner_id' => $owner->id,
            'title' => 'Feedback Project',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'capacity' => 2,
            'version' => 1,
        ]);
    }

    private function createRecommendation(User $learner, Project $project): Recommendation
    {
        return Recommendation::create([
            'user_id' => $learner->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'algorithm_version' => 'project-match-v1',
            'configuration_version' => 'config-v1',
            'generated_at' => now(),
        ]);
    }

    public function test_unauthenticated_feedback_is_rejected(): void
    {
        $learner = $this->createLearner();
        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event_type' => 'save'])
            ->assertStatus(401);
    }

    public function test_saving_a_recommendation_records_a_save_event(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'save',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.event_type', 'save');

        $this->assertDatabaseHas('feedback_events', [
            'recommendation_id' => $recommendation->id,
            'user_id' => $learner->id,
            'event_type' => 'save',
        ]);
    }

    public function test_hiding_a_recommendation_records_a_hide_event(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'hide',
            'reason' => 'Not interested right now',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.event_type', 'hide');

        $this->assertDatabaseHas('feedback_events', [
            'recommendation_id' => $recommendation->id,
            'event_type' => 'hide',
            'reason' => 'Not interested right now',
        ]);
    }

    public function test_declining_a_recommendation_maps_onto_the_existing_reject_event(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'decline',
            'reason' => 'Wrong tech stack',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.event_type', 'reject');

        // `decline` is stored as the pre-existing `reject` event — no new
        // parallel event type was introduced.
        $this->assertDatabaseHas('feedback_events', [
            'recommendation_id' => $recommendation->id,
            'event_type' => 'reject',
        ]);
    }

    public function test_an_unsupported_event_type_is_rejected(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'teleport',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_feedback_without_an_event_type_is_rejected(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_a_learner_cannot_leave_feedback_on_someone_elses_recommendation(): void
    {
        // NOTE: deliberately not 'owner@test.com' — createProject() already uses
        // that address for the project owner, and users.email is unique.
        $owner = $this->createLearner('rec-owner@test.com');
        $attacker = $this->createLearner('attacker@test.com');
        Sanctum::actingAs($attacker);

        $recommendation = $this->createRecommendation($owner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'save',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'RECOMMENDATION_NOT_OWNED');

        $this->assertDatabaseCount('feedback_events', 0);
    }

    public function test_feedback_on_a_missing_recommendation_returns_404(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/recommendations/999999/feedback', ['event_type' => 'save'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'RECOMMENDATION_NOT_FOUND');
    }

    public function test_feedback_writes_an_audit_row(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", [
            'event_type' => 'save',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $learner->id,
            'action' => 'recommendation.feedback.save',
            'entity_type' => 'recommendation',
            'entity_id' => $recommendation->id,
            'purpose' => 'recommendation_feedback',
        ]);
    }

    public function test_repeated_feedback_is_recorded_as_separate_events(): void
    {
        // Unlike notifications, feedback is an append-only log: saving twice is
        // two distinct learner actions and both are kept.
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event_type' => 'save'])
            ->assertStatus(201);
        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event_type' => 'save'])
            ->assertStatus(201);

        $this->assertSame(2, FeedbackEvent::where('recommendation_id', $recommendation->id)->count());
    }

    public function test_no_suppression_window_is_applied(): void
    {
        // US-MATCH-02 mentions suppressing hidden/declined recommendations "for
        // a configured period", but no such configuration exists. This test
        // pins the ACTUAL behaviour: only the event is recorded, nothing is
        // suppressed, and the recommendation remains readable. If a suppression
        // policy is ever approved this test must change deliberately.
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event_type' => 'hide'])
            ->assertStatus(201);

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $recommendation->id);
    }

    public function test_the_existing_relation_still_reads_the_events(): void
    {
        // Proves the feedback is readable through the pre-existing model
        // relation, i.e. no parallel store was created.
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $recommendation = $this->createRecommendation($learner, $this->createProject());

        $this->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event_type' => 'hide'])
            ->assertStatus(201);

        $events = $recommendation->fresh()->feedbackEvents;

        $this->assertCount(1, $events);
        $this->assertSame('hide', $events->first()->event_type);
    }
}
