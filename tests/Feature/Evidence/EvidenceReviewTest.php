<?php

namespace Tests\Feature\Evidence;

use App\Events\SkillDataChanged;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for EvidenceController::review().
 *
 * There was no test for this endpoint at all, which is how a dead-code bug
 * survived in it: the method returned early, so the SkillDataChanged
 * dispatch and the `data` key in the response were both unreachable. The
 * queued intelligence recalculation silently never fired.
 */
class EvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user;
    }

    private function evidence(string $status = 'pending'): SkillEvidence
    {
        $learner = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $learner->roles()->attach(Role::where('slug', 'learner')->first()->id);

        $profile = StudentProfile::forceCreate(['user_id' => $learner->id]);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);

        return SkillEvidence::create([
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
            'source' => 'project_performance',
            'value' => 4.0,
            'normalized_value' => 0.80,
            'evidence_date' => now(),
            'verification_status' => $status,
        ]);
    }

    /**
     * The core regression: reviewing evidence must enqueue the intelligence
     * recalculation. Before the fix this assertion failed because the
     * dispatch sat after an early return.
     */
    public function test_review_dispatches_skill_data_changed(): void
    {
        Event::fake();
        Sanctum::actingAs($this->admin());
        $evidence = $this->evidence();

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertOk();

        Event::assertDispatched(
            SkillDataChanged::class,
            fn (SkillDataChanged $event) => $event->studentProfile->id === $evidence->student_profile_id
        );
    }

    /**
     * The second half of the same bug: the response must carry the reviewed
     * record. Callers used to get only `success` and `message`.
     */
    public function test_review_returns_the_reviewed_record(): void
    {
        Event::fake();
        Sanctum::actingAs($this->admin());
        $evidence = $this->evidence();

        $response = $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertOk();

        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $evidence->id)
            ->assertJsonPath('data.verification_status', 'verified');

        // The reviewer must be recorded, not just the status.
        $this->assertNotNull($evidence->fresh()->reviewer_id);
    }

    /**
     * A rejection is also a skill-data change (it lowers the effective
     * level), so it must enqueue a recalculation too.
     */
    public function test_rejection_also_dispatches_skill_data_changed(): void
    {
        Event::fake();
        Sanctum::actingAs($this->admin());
        $evidence = $this->evidence();

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'rejected',
        ])->assertOk();

        Event::assertDispatched(SkillDataChanged::class);
        $this->assertSame('rejected', $evidence->fresh()->verification_status);
    }
}
