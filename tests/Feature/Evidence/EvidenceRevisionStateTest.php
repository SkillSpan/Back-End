<?php

namespace Tests\Feature\Evidence;

use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Skills\SkillEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvidenceRevisionStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user;
    }

    private function admin(): User
    {
        return $this->user('admin');
    }

    private function learner(): User
    {
        return $this->user('learner');
    }

    private function learnerWithEvidence(string $status = 'pending', string $source = 'certificate'): array
    {
        $learner = $this->learner();
        $profile = StudentProfile::forceCreate(['user_id' => $learner->id]);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);

        $evidence = SkillEvidence::create([
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
            'source' => $source,
            'value' => 4.0,
            'normalized_value' => 0.80,
            'evidence_date' => now(),
            'verification_status' => $status,
        ]);

        return [$learner, $profile, $skill, $evidence];
    }

    public function test_an_admin_can_request_revision(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
            'reviewer_notes' => 'Please upload the certificate as a PDF instead of a photo.',
        ])->assertOk();

        $this->assertDatabaseHas('skill_evidences', [
            'id' => $evidence->id,
            'verification_status' => 'revision',
        ]);

        $this->assertNotNull($evidence->fresh()->reviewer_id);
    }

    public function test_the_revision_outcome_is_returned_in_the_review_response(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verification_status', 'revision')
            ->assertJsonPath('data.reviewer_id', $admin->id);
    }

    public function test_the_review_message_for_revision_is_grammatical(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Revision requested for the evidence successfully.');
    }

    public function test_revision_survives_reload_and_is_visible_to_the_owner(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$learner, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
        ])->assertOk();

        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/evidence/{$evidence->id}")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'revision');

        $this->assertSame('revision', SkillEvidence::find($evidence->id)->verification_status);
    }

    public function test_revision_evidence_can_later_be_verified(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('revision');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertOk();

        $this->assertSame('verified', $evidence->fresh()->verification_status);
    }

    public function test_revision_evidence_can_later_be_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('revision');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'rejected',
        ])->assertOk();

        $this->assertSame('rejected', $evidence->fresh()->verification_status);
    }

    public function test_a_second_clarification_request_is_allowed(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('revision');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
            'reviewer_notes' => 'Still not readable — please re-scan.',
        ])->assertOk();

        $this->assertSame('revision', $evidence->fresh()->verification_status);
    }

    public function test_verified_evidence_cannot_be_reviewed_again(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('verified');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'rejected',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_EVIDENCE_TRANSITION');

        $this->assertSame('verified', $evidence->fresh()->verification_status);
    }

    public function test_rejected_evidence_cannot_be_reviewed_again(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('rejected');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_EVIDENCE_TRANSITION');

        $this->assertSame('rejected', $evidence->fresh()->verification_status);
    }

    public function test_expired_evidence_cannot_be_reviewed(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('expired');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_EVIDENCE_TRANSITION');
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors('verification_status');
    }

    public function test_pending_is_not_an_accepted_review_outcome(): void
    {
        Sanctum::actingAs($this->admin());
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'pending',
        ])->assertStatus(422)->assertJsonValidationErrors('verification_status');
    }

    public function test_the_three_review_outcomes_are_accepted_and_nothing_else(): void
    {
        $this->assertSame(['verified', 'rejected', 'revision'], SkillEvidence::REVIEW_OUTCOMES);
        $this->assertSame(['pending', 'revision'], SkillEvidence::REVIEWABLE_STATUSES);
        $this->assertSame(
            ['pending', 'verified', 'rejected', 'expired', 'revision'],
            SkillEvidence::STATUSES
        );
    }

    public function test_revision_evidence_does_not_raise_skill_level_or_confidence(): void
    {
        [, $profile, $skill] = $this->learnerWithEvidence('revision');

        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill);

        $this->assertSame(0.0, (float) $evaluation->level);
        $this->assertSame(0.0, (float) $evaluation->confidence);

        $records = $evaluation->snapshot['sources']['certificate']['records'] ?? [];
        $this->assertCount(1, $records);
        $this->assertSame('revision', $records[0]['verification_status']);
        $this->assertSame(0.0, (float) $records[0]['verification_factor']);
    }

    public function test_verified_evidence_still_raises_the_level_and_confidence(): void
    {
        [, $profile, $skill] = $this->learnerWithEvidence('verified');

        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill);

        $this->assertGreaterThan(0.0, (float) $evaluation->level);
        $this->assertGreaterThan(0.0, (float) $evaluation->confidence);
    }

    public function test_a_learner_cannot_request_revision(): void
    {
        Sanctum::actingAs($this->learner());
        [, , , $evidence] = $this->learnerWithEvidence('pending');

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'revision',
        ])->assertStatus(403);

        $this->assertSame('pending', $evidence->fresh()->verification_status);
    }
}
