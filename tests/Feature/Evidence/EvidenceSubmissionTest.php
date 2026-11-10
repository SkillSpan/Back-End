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
