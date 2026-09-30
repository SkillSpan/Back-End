<?php

namespace Tests\Feature\Evidence;

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
 * Regression coverage for EvidenceController::store().
 *
 * The endpoint validated a `description` input and then never persisted it:
 * the field was absent from `SkillEvidence::$fillable` and `skill_evidences`
 * had no such column. A learner who filled the description box got a 201 and
 * silently lost the text, with no error to show for it — which is exactly what
 * was reported as "the admin can't read the description even though I typed it
 * in the form".
 *
 * The tests below deliberately drive the real HTTP endpoint rather than
 * writing the column by hand. A test that seeds `description` directly proves
 * only that the database accepts it, which was never in doubt — the bug lived
 * in the hand-off between validation and `create()`.
 */
class EvidenceSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function learnerWithProfile(): User
    {
        $user = $this->learner();
        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);
    }

    /**
     * The core regression. A description sent to the real endpoint must be
     * stored — not merely validated.
     */
    public function test_a_submitted_description_is_persisted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
            'description' => 'Completed the advanced SQL course with distinction.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('skill_evidences', [
            'skill_id' => $skill->id,
            'description' => 'Completed the advanced SQL course with distinction.',
        ]);
    }

    /**
     * And it must come back out through the detail endpoint the admin panel
     * and the learner's own view both read.
     */
    public function test_the_description_is_returned_by_the_detail_endpoint(): void
    {
        $learner = $this->learnerWithProfile();
        Sanctum::actingAs($learner);
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
            'description' => 'Built a reporting pipeline used by three teams.',
        ])->assertStatus(201);

        $id = $created->json('data.id');

        $this->getJson("/api/v1/evidence/{$id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Built a reporting pipeline used by three teams.');
    }

    /**
     * The value must also survive a review — an admin approving the evidence
     * must not blank the learner's description.
     */
    public function test_review_does_not_clear_the_description(): void
    {
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $learner = $this->learnerWithProfile();
        Sanctum::actingAs($learner);
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
            'description' => 'Sentence kept across review.',
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($created->json('data.id'));
        $this->assertSame('Sentence kept across review.', $evidence->description);

        $admin = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
            'reviewer_notes' => 'Looks good.',
        ])->assertOk();

        $this->assertSame('Sentence kept across review.', $evidence->fresh()->description);
    }

    /**
     * Omitting the description is still valid — it is optional — and must
     * store null rather than an empty string, so a client can tell
     * "nothing submitted" from "submitted blank".
     */
    public function test_omitting_the_description_stores_null(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
        ])->assertStatus(201);

        $this->assertNull(SkillEvidence::findOrFail($created->json('data.id'))->description);
    }

    /**
     * The validation contract must not change: a description over 500
     * characters is still refused, and the column is sized to match.
     */
    public function test_a_description_over_500_characters_is_rejected(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
            'description' => str_repeat('a', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    /**
     * A description of exactly 500 characters is still accepted, so the
     * column width and the validation cap agree at the boundary.
     */
    public function test_a_description_of_exactly_500_characters_is_accepted(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $boundary = str_repeat('b', 500);

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/my-certificate',
            'description' => $boundary,
        ])->assertStatus(201);

        $this->assertSame(
            $boundary,
            SkillEvidence::findOrFail($created->json('data.id'))->description
        );
    }
}
