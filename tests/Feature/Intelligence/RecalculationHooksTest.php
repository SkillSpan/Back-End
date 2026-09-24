<?php

namespace Tests\Feature\Intelligence;

use App\Events\SkillDataChanged;
use App\Models\AlgorithmConfiguration;
use App\Models\BaselineAssessment;
use App\Models\CareerGoalHistory;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-INT-01 §24 — recalculation hooks: approved data changes fire
 * SkillDataChanged; the queued listener recalculates intelligence; a
 * career-role change is tracked in CareerGoalHistory.
 */
class RecalculationHooksTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        // US-INT-01: the service credential is mandatory on every
        // Laravel -> FastAPI intelligence request.
        config(['services.data_science.service_token' => 'test-service-token']);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    public function test_career_role_change_fires_event_and_records_history(): void
    {
        Event::fake([SkillDataChanged::class]);

        $user = $this->createLearner();
        $oldRole = $this->approvedRole('Data Analyst');
        $newRole = $this->approvedRole('Backend Developer');

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $oldRole->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'primary_career_role_id' => $newRole->id,
        ])->assertOk();

        Event::assertDispatched(SkillDataChanged::class, fn ($event) => $event->source === 'career_role_change'
            && $event->studentProfile->id === $profile->id);

        $this->assertDatabaseHas('career_goal_history', [
            'student_profile_id' => $profile->id,
            'career_role_id' => $newRole->id,
        ]);
    }

    public function test_unrelated_profile_update_does_not_fire_event(): void
    {
        Event::fake([SkillDataChanged::class]);

        $user = $this->createLearner();
        $role = $this->approvedRole('Data Analyst');

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'part_time',
            'primary_career_role_id' => $role->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'bio' => 'updated bio text',
        ])->assertOk();

        Event::assertNotDispatched(SkillDataChanged::class);
    }

    public function test_baseline_submit_dispatches_event(): void
    {
        Event::fake([SkillDataChanged::class]);

        config(['services.data_science.baseline.enabled' => true]);
        config(['services.data_science.baseline.version' => 'v1.0']);

        $user = $this->createLearner();
        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid(), 'status' => 'active']);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
            'progress' => [],
            'responses' => null,
            'result' => null,
            'normalized_skills' => null,
            'completed_at' => null,
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'student_profile_id' => $profile->id,
                'overall_score' => 60,
                'skills' => [
                    ['skill_id' => $skill->id, 'slug' => $skill->slug, 'level' => 3.0, 'confidence' => 0.7],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question' => 1, 'answer' => 'sql-2'],
            ],
        ])->assertOk();

        Event::assertDispatched(SkillDataChanged::class, fn ($e) => $e->source === 'baseline_assessment_submit'
            && $e->studentProfile->id === $profile->id);
    }

    public function test_listener_calculates_intelligence_for_primary_role(): void
    {
        // End-to-end through the real listener + real service.
        $user = $this->createLearner();
        $role = $this->approvedRole('Data Analyst');
        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid(), 'status' => 'active']);
        $role->roleSkills()->create([
            'skill_id' => $skill->id,
            'required_level' => 4.0,
            'importance_weight' => 0.5,
            'is_critical' => true,
        ]);

        SkillEvaluation::forceCreate([
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
            'level' => 2.5,
            'confidence' => 90,
            'algorithm_version' => 'baseline-v1',
            'calculated_at' => now(),
        ]);

        // ONE response: the deployed contract returns the per-skill gaps
        // AND the readiness block from POST /api/v1/skill-gap together,
        // so a calculation is a single round-trip. (A fakeSequence would
        // leave the second queued response unconsumed.)
        Http::fake([
            '*/api/v1/skill-gap' => Http::response([
                'student_profile_id' => $profile->id,
                'career_role_id' => $role->id,
                'career_role_version' => 1,
                'algorithm_version' => 'intelligence-v1',
                'skill_results' => [[
                    'skill_id' => $skill->id,
                    'skill_name' => $skill->name,
                    'current_level' => 2.5,
                    'required_level' => 4.0,
                    'importance_weight' => 0.5,
                    'is_critical' => true,
                    'gap' => 1.5,
                    'status' => 'gap',
                ]],
                'base_readiness_score' => 50.0,
                'readiness_score' => 50.0,
                'critical_skill_cap_applied' => false,
                'critical_skill_gap_count' => 0,
                'critical_skill_names' => [],
                'total_skills' => 1,
                'met_skills' => 0,
                'skills_with_gap' => 1,
            ], 200),
        ]);

        // QUEUE_CONNECTION=sync in tests: the listener runs inline.
        SkillDataChanged::dispatch($profile->fresh(), 'career_role_change');

        $this->assertDatabaseHas('decision_snapshots', [
            'student_profile_id' => $profile->id,
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('readiness_results', [
            'student_profile_id' => $profile->id,
            'score' => 50.00,
        ]);
    }

    public function test_listener_failure_is_swallowed_not_fatal(): void
    {
        $user = $this->createLearner();
        $role = $this->approvedRole('Data Analyst');
        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'primary_career_role_id' => $role->id,
        ]);

        // No skill evaluations at all → calculation throws inside the
        // listener. The dispatch itself must not blow up the caller.
        SkillDataChanged::dispatch($profile->fresh(), 'career_role_change');

        $this->assertTrue(true, 'listener failures are logged and swallowed');
    }

    public function test_career_goal_history_closes_previous_entry(): void
    {
        Event::fake([SkillDataChanged::class]);

        $user = $this->createLearner();
        $firstRole = $this->approvedRole('Data Analyst');
        $secondRole = $this->approvedRole('Backend Developer');

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'primary_career_role_id' => $firstRole->id,
        ]);

        CareerGoalHistory::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $firstRole->id,
            'career_role_version' => 1,
            'set_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'primary_career_role_id' => $secondRole->id,
        ])->assertOk();

        $this->assertDatabaseHas('career_goal_history', [
            'id' => 1,
            'career_role_id' => $firstRole->id,
        ]);
        $this->assertNotNull(CareerGoalHistory::find(1)->replaced_at);
        $this->assertDatabaseHas('career_goal_history', [
            'career_role_id' => $secondRole->id,
            'replaced_at' => null,
        ]);
    }

    private function createLearner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function approvedRole(string $title)
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);
    }
}
