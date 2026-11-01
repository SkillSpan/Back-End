<?php

namespace Tests\Feature\Skills;

use App\Models\LearnerSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DELETE /api/v1/learner/skills/{skill}
 *
 * The endpoint removes ONE skill from the authenticated learner's own list by
 * deleting the `learner_skills` row. The `skills` row is shared taxonomy and
 * must survive — this suite exists mostly to prove that it does, and that no
 * other learner's row is ever touched.
 */
class LearnerSkillRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Super Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function learner(string $email): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => null]);

        return $user;
    }

    private function admin(string $email = 'admin@test.com'): User
    {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id, ['organization_id' => null]);

        return $user;
    }

    private function skill(string $name = 'SQL'): Skill
    {
        return Skill::forceCreate([
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
            'version' => 1,
        ]);
    }

    private function attachSkill(User $learner, Skill $skill, float $level = 3.0): LearnerSkill
    {
        return LearnerSkill::forceCreate([
            'learner_id' => $learner->id,
            'skill_id' => $skill->id,
            'level' => $level,
            'confidence_score' => 70,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 1 — a learner can remove their own skill
    // ──────────────────────────────────────────────────────────────
    public function test_learner_can_remove_a_skill_from_their_own_list(): void
    {
        $me = $this->learner('me@test.com');
        $sql = $this->skill('SQL');
        $this->attachSkill($me, $sql);

        Sanctum::actingAs($me);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'Skill removed successfully.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('learner_skills', [
            'learner_id' => $me->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 2 — the skill itself still exists in the system
    // ──────────────────────────────────────────────────────────────
    public function test_removing_a_skill_does_not_delete_the_skill_itself(): void
    {
        $me = $this->learner('me@test.com');
        $sql = $this->skill('SQL');
        $this->attachSkill($me, $sql);

        Sanctum::actingAs($me);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(200);

        // The taxonomy row is untouched...
        $this->assertDatabaseHas('skills', [
            'id' => $sql->id,
            'name' => 'SQL',
            'slug' => 'sql',
        ]);

        // ...and it is still resolvable through the model, i.e. it was not
        // soft-deleted or otherwise hidden either.
        $this->assertNotNull(Skill::find($sql->id));
    }

    // ──────────────────────────────────────────────────────────────
    // Test 3 — other learners keep the same skill
    // ──────────────────────────────────────────────────────────────
    public function test_removing_a_skill_does_not_affect_other_learners(): void
    {
        $studentA = $this->learner('a@test.com');
        $studentB = $this->learner('b@test.com');
        $sql = $this->skill('SQL');

        $this->attachSkill($studentA, $sql, 3.0);
        $this->attachSkill($studentB, $sql, 4.5);

        Sanctum::actingAs($studentA);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(200);

        $this->assertDatabaseMissing('learner_skills', [
            'learner_id' => $studentA->id,
            'skill_id' => $sql->id,
        ]);

        // B's relation — including its level — must be exactly as it was.
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $studentB->id,
            'skill_id' => $sql->id,
            'level' => 4.5,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 4 — cannot remove a skill the caller does not have
    // ──────────────────────────────────────────────────────────────
    public function test_learner_cannot_remove_another_learners_skill(): void
    {
        $studentA = $this->learner('a@test.com');
        $studentB = $this->learner('b@test.com');
        $sql = $this->skill('SQL');

        // Only B has it.
        $this->attachSkill($studentB, $sql);

        Sanctum::actingAs($studentA);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'Skill is not associated with this learner.',
                'data' => null,
            ]);

        // The critical assertion: B's row survived.
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $studentB->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Test 5 — unauthenticated callers are rejected
    // ──────────────────────────────────────────────────────────────
    public function test_unauthenticated_request_is_rejected(): void
    {
        $owner = $this->learner('owner@test.com');
        $sql = $this->skill('SQL');
        $this->attachSkill($owner, $sql);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(401);

        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $owner->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Authorization — a non-learner account is refused
    // ──────────────────────────────────────────────────────────────
    public function test_non_learner_account_cannot_remove_a_skill(): void
    {
        $admin = $this->admin();
        $learner = $this->learner('learner@test.com');
        $sql = $this->skill('SQL');
        $this->attachSkill($learner, $sql);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(403);

        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $learner->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Security — learner_id in the payload is ignored
    // ──────────────────────────────────────────────────────────────
    public function test_learner_id_in_the_request_is_ignored(): void
    {
        $studentA = $this->learner('a@test.com');
        $studentB = $this->learner('b@test.com');
        $sql = $this->skill('SQL');

        $this->attachSkill($studentA, $sql);
        $this->attachSkill($studentB, $sql);

        Sanctum::actingAs($studentA);

        // A caller tries to point the delete at another learner.
        $this->deleteJson('/api/v1/learner/skills/'.$sql->id, [
            'learner_id' => $studentB->id,
        ])->assertStatus(200);

        // A's own row went; B's did not. The body value had no effect.
        $this->assertDatabaseMissing('learner_skills', [
            'learner_id' => $studentA->id,
            'skill_id' => $sql->id,
        ]);
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $studentB->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // A skill id that does not exist is the same 404 as "not yours"
    // ──────────────────────────────────────────────────────────────
    public function test_removing_a_nonexistent_skill_returns_404_and_deletes_nothing(): void
    {
        $me = $this->learner('me@test.com');
        $sql = $this->skill('SQL');
        $this->attachSkill($me, $sql);

        Sanctum::actingAs($me);

        $this->deleteJson('/api/v1/learner/skills/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Skill is not associated with this learner.');

        // Untouched: the caller's other rows survive.
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $me->id,
            'skill_id' => $sql->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Only the targeted relation is removed, not the learner's other skills
    // ──────────────────────────────────────────────────────────────
    public function test_only_the_targeted_relation_is_removed(): void
    {
        $me = $this->learner('me@test.com');
        $sql = $this->skill('SQL');
        $php = $this->skill('PHP');

        $this->attachSkill($me, $sql);
        $this->attachSkill($me, $php);

        Sanctum::actingAs($me);

        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(200);

        $this->assertDatabaseMissing('learner_skills', [
            'learner_id' => $me->id,
            'skill_id' => $sql->id,
        ]);
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $me->id,
            'skill_id' => $php->id,
        ]);

        // Both taxonomy rows are still there.
        $this->assertSame(2, Skill::count());
    }

    // ──────────────────────────────────────────────────────────────
    // Test 6 — the existing add/retrieve skill APIs still work
    // ──────────────────────────────────────────────────────────────
    public function test_existing_learner_skill_apis_still_work(): void
    {
        $me = $this->learner('me@test.com');
        $sql = $this->skill('SQL');

        Sanctum::actingAs($me);

        // Add through the existing endpoint.
        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $sql->id,
            'level' => 3.5,
            'confidence_score' => 80,
        ])->assertStatus(201);

        $this->getJson('/api/v1/skills/matrix')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.skill_id', $sql->id);

        // Remove through the new one...
        $this->deleteJson('/api/v1/learner/skills/'.$sql->id)->assertStatus(200);

        $this->getJson('/api/v1/skills/matrix')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // ...and the skill can be added back afterwards, which proves the
        // delete did not leave a broken foreign key behind.
        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $sql->id,
            'level' => 2.0,
            'confidence_score' => 40,
        ])->assertStatus(201);

        $this->getJson('/api/v1/skills/matrix')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
