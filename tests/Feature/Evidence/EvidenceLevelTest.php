<?php

namespace Tests\Feature\Evidence;

use App\Models\LearnerSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvidenceLevelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
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

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);
    }

    public function test_a_declared_level_is_stored_as_the_normalized_value(): void
    {
        Sanctum::actingAs($this->learner());
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
            'level' => 3.5,
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($created->json('data.id'));

        $this->assertSame(3.5, (float) $evidence->value);
        $this->assertSame(3.5, (float) $evidence->normalized_value);
    }

    public function test_omitting_the_level_still_stores_zero(): void
    {
        Sanctum::actingAs($this->learner());
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($created->json('data.id'));

        $this->assertSame(0.0, (float) $evidence->value);
        $this->assertSame(0.0, (float) $evidence->normalized_value);
    }

    public function test_a_level_outside_the_zero_to_five_scale_is_rejected(): void
    {
        Sanctum::actingAs($this->learner());
        $skill = $this->skill();

        $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
            'level' => 6,
        ])->assertStatus(422)->assertJsonValidationErrors('level');
    }

    public function test_verified_evidence_with_a_level_produces_a_non_zero_skill_matrix(): void
    {
        $learner = $this->learner();
        $skill = $this->skill();

        Sanctum::actingAs($learner);

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
            'level' => 4,
        ])->assertStatus(201);

        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/evidence/'.$created->json('data.id').'/review', [
            'verification_status' => 'verified',
            'reviewer_notes' => 'Confirmed.',
        ])->assertOk();

        $this->assertSame(
            4.0,
            (float) LearnerSkill::where('learner_id', $learner->id)
                ->where('skill_id', $skill->id)
                ->value('level'),
        );

        Sanctum::actingAs($learner);

        $matrix = $this->getJson('/api/v1/skills/matrix')->assertOk();

        $this->assertNotEmpty($matrix->json('data'));

        $row = collect($matrix->json('data'))
            ->firstWhere('skill_id', $skill->id);

        $this->assertNotNull($row);
        $this->assertGreaterThan(0, (float) $row['level']);
    }

    public function test_a_reviewer_can_correct_the_level_of_evidence_submitted_without_one(): void
    {
        $learner = $this->learner();
        $skill = $this->skill();

        Sanctum::actingAs($learner);

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
        ])->assertStatus(201);

        $evidence = SkillEvidence::findOrFail($created->json('data.id'));
        $this->assertSame(0.0, (float) $evidence->normalized_value);

        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/evidence/'.$evidence->id.'/review', [
            'verification_status' => 'verified',
            'level' => 3,
        ])->assertOk();

        $this->assertSame(3.0, (float) $evidence->fresh()->normalized_value);

        $this->assertSame(
            3.0,
            (float) LearnerSkill::where('learner_id', $learner->id)
                ->where('skill_id', $skill->id)
                ->value('level'),
        );
    }

    public function test_a_typeless_submission_is_stored_with_a_null_evidence_type(): void
    {
        Sanctum::actingAs($this->learner());
        $skill = $this->skill();

        $created = $this->postJson('/api/v1/evidence', [
            'skill_id' => $skill->id,
            'evidence_url' => 'https://example.com/certificate',
        ])->assertStatus(201);

        $this->assertNull(SkillEvidence::findOrFail($created->json('data.id'))->evidence_type);
    }
}
