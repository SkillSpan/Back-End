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
 * Regression tests for the write-side IDOR on /api/v1/skills/matrix.
 *
 * SkillsMatrixTest covers the read path only. The write path (POST and PUT)
 * previously accepted any learner_id / any row id from any authenticated
 * user, so a learner could create or overwrite another learner's skill
 * levels and confidence scores. These tests pin the authorization rules
 * that now apply to those two endpoints.
 */
class SkillsMatrixAuthorizationTest extends TestCase
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

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id, ['organization_id' => null]);

        return $user;
    }

    private function skill(string $name = 'JavaScript'): Skill
    {
        return Skill::forceCreate([
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
            'version' => 1,
        ]);
    }

    public function test_learner_can_write_their_own_skill_record(): void
    {
        $me = $this->learner('me@test.com');
        $skill = $this->skill();

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 3.5,
            'confidence_score' => 80,
        ])->assertStatus(201);

        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_learner_cannot_create_a_skill_record_for_another_learner(): void
    {
        $me = $this->learner('me@test.com');
        $victim = $this->learner('victim@test.com');
        $skill = $this->skill();

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
            'level' => 5.0,
            'confidence_score' => 100,
        ])->assertStatus(403);

        // The critical assertion: nothing was written for the victim.
        $this->assertDatabaseMissing('learner_skills', [
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_learner_cannot_overwrite_an_existing_record_for_another_learner(): void
    {
        $me = $this->learner('me@test.com');
        $victim = $this->learner('victim@test.com');
        $skill = $this->skill();

        LearnerSkill::forceCreate([
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
            'level' => 1.0,
            'confidence_score' => 10,
        ]);

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
            'level' => 5.0,
            'confidence_score' => 100,
        ])->assertStatus(403);

        // The victim's original values must survive untouched.
        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
            'level' => 1.0,
        ]);
    }

    public function test_admin_can_write_a_skill_record_for_any_learner(): void
    {
        $admin = $this->admin();
        $target = $this->learner('target@test.com');
        $skill = $this->skill();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $target->id,
            'skill_id' => $skill->id,
            'level' => 4.0,
            'confidence_score' => 90,
        ])->assertStatus(201);

        $this->assertDatabaseHas('learner_skills', [
            'learner_id' => $target->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_learner_updating_another_learners_row_gets_404(): void
    {
        $me = $this->learner('me@test.com');
        $victim = $this->learner('victim@test.com');
        $skill = $this->skill();

        $victimRow = LearnerSkill::forceCreate([
            'learner_id' => $victim->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 20,
        ]);

        Sanctum::actingAs($me);

        // 404 rather than 403: the row is simply not visible to this caller,
        // so the response does not confirm that the id exists.
        $this->putJson('/api/v1/skills/matrix/'.$victimRow->id, [
            'level' => 5.0,
        ])->assertStatus(404);

        $this->assertDatabaseHas('learner_skills', [
            'id' => $victimRow->id,
            'level' => 2.0,
        ]);
    }

    public function test_learner_can_update_their_own_row(): void
    {
        $me = $this->learner('me@test.com');
        $skill = $this->skill();

        $myRow = LearnerSkill::forceCreate([
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 20,
        ]);

        Sanctum::actingAs($me);

        $this->putJson('/api/v1/skills/matrix/'.$myRow->id, [
            'level' => 4.5,
        ])->assertStatus(200);

        $this->assertDatabaseHas('learner_skills', [
            'id' => $myRow->id,
            'level' => 4.5,
        ]);
    }

    public function test_admin_can_update_another_learners_row(): void
    {
        $admin = $this->admin();
        $target = $this->learner('target@test.com');
        $skill = $this->skill();

        $row = LearnerSkill::forceCreate([
            'learner_id' => $target->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 20,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/skills/matrix/'.$row->id, [
            'level' => 4.5,
        ])->assertStatus(200);

        $this->assertDatabaseHas('learner_skills', [
            'id' => $row->id,
            'level' => 4.5,
        ]);
    }
}
