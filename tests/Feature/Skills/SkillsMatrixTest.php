<?php

namespace Tests\Feature\Skills;

use App\Models\LearnerSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SkillsMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Super Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function learner(string $email = 'learner@test.com'): User
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

    /**
     * Regression test for the fix documented in SkillsController::matrix():
     * a non-admin used to receive the *entire* learner_skills table
     * (every learner's rows) because $learnerId/$careerRoleId were method
     * arguments the route never populated. This locks in that a learner
     * only ever sees their own skills.
     */
    public function test_learner_only_sees_their_own_skills_by_default(): void
    {
        $me = $this->learner('me@test.com');
        $someoneElse = $this->learner('someone-else@test.com');
        $skill = $this->skill();

        LearnerSkill::forceCreate([
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 3.5,
            'confidence_score' => 80,
        ]);

        LearnerSkill::forceCreate([
            'learner_id' => $someoneElse->id,
            'skill_id' => $skill->id,
            'level' => 4.0,
            'confidence_score' => 90,
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/skills/matrix');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.learner_id', $me->id);
    }

    /**
     * A learner passing ?learner_id= for another user must NOT be able to
     * view that user's skills — the controller ignores the query param for
     * non-admins and always scopes to $user->id instead.
     */
    public function test_learner_cannot_view_another_learners_skills_via_query_param(): void
    {
        $me = $this->learner('me@test.com');
        $someoneElse = $this->learner('someone-else@test.com');
        $skill = $this->skill();

        LearnerSkill::forceCreate([
            'learner_id' => $someoneElse->id,
            'skill_id' => $skill->id,
            'level' => 4.0,
            'confidence_score' => 90,
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/skills/matrix?learner_id='.$someoneElse->id);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Admins are the one role explicitly allowed to inspect another
     * learner's matrix, and only via the explicit ?learner_id= query param.
     */
    public function test_admin_can_view_a_specific_learners_skills_via_query_param(): void
    {
        $admin = $this->admin();
        $targetLearner = $this->learner('target@test.com');
        $otherLearner = $this->learner('other@test.com');
        $skill = $this->skill();

        LearnerSkill::forceCreate([
            'learner_id' => $targetLearner->id,
            'skill_id' => $skill->id,
            'level' => 3.0,
            'confidence_score' => 70,
        ]);

        LearnerSkill::forceCreate([
            'learner_id' => $otherLearner->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 60,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/skills/matrix?learner_id='.$targetLearner->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.learner_id', $targetLearner->id);
    }

    /**
     * An admin calling the endpoint with NO learner_id filter intentionally
     * still sees every learner's rows (documented "admin overview" behavior
     * in the controller) — this pins that behavior down explicitly so it
     * isn't mistaken for a bug later.
     */
    public function test_admin_without_filter_sees_every_learners_skills(): void
    {
        $admin = $this->admin();
        $learnerA = $this->learner('a@test.com');
        $learnerB = $this->learner('b@test.com');
        $skill = $this->skill();

        LearnerSkill::forceCreate([
            'learner_id' => $learnerA->id,
            'skill_id' => $skill->id,
            'level' => 3.0,
            'confidence_score' => 70,
        ]);

        LearnerSkill::forceCreate([
            'learner_id' => $learnerB->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 60,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/skills/matrix');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
