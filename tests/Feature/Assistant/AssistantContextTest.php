<?php

namespace Tests\Feature\Assistant;

use App\Models\Application;
use App\Models\CareerRole;
use App\Models\DecisionSnapshot;
use App\Models\Project;
use App\Models\ReadinessResult;
use App\Models\Recommendation;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Assistant\AssistantContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US-REC-01 — the approved learner context snapshot.
 *
 * These are the guarantees that hold regardless of what FastAPI returns,
 * and the ones the SRS is strictest about:
 *
 *  - REC-07 / §12.5: nothing is fabricated. A source with no row for this
 *    learner is reported as unavailable, never defaulted and never omitted.
 *  - BR-REC-02 / BR-06: nothing is recomputed. Stored values are read back
 *    verbatim.
 *  - BR-11: no query can reach another learner's data.
 */
class AssistantContextTest extends TestCase
{
    use RefreshDatabase;

    private AssistantContextBuilder $builder;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(AssistantContextBuilder::class);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    // ------------------------------------------- no fabrication (REC-07)

    public function test_every_section_is_explicitly_unavailable_for_a_bare_learner(): void
    {
        [, $profile] = $this->createLearner();

        $snapshot = $this->builder->build($profile)['snapshot'];

        $sections = [
            'readiness',
            'skill_gaps',
            'roadmap',
            'next_best_action',
            'project_recommendations',
            'project_applications',
        ];

        foreach ($sections as $section) {
            $this->assertArrayHasKey($section, $snapshot, "Section [{$section}] was silently omitted.");

            // Present, but explicitly marked unknown — not zero, not null,
            // not a plausible default.
            $this->assertFalse(
                $snapshot[$section]['available'],
                "Section [{$section}] should be marked unavailable for a learner with no stored data.",
            );
            $this->assertNotEmpty(
                $snapshot[$section]['reason'],
                "Section [{$section}] is unavailable but gives no reason.",
            );
        }
    }

    public function test_a_learner_with_no_primary_career_role_is_reported_as_unavailable(): void
    {
        [, $profile] = $this->createLearner();

        $careerRole = $this->builder->build($profile)['snapshot']['career_role'];

        $this->assertFalse($careerRole['available']);
    }

    // ---------------------------------------- no recomputation (BR-06)

    public function test_readiness_is_read_back_exactly_as_stored(): void
    {
        [, $profile, $role] = $this->createLearner(withRole: true);

        ReadinessResult::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'score' => 72.5,
            'band' => 'near_ready',
            'critical_cap_applied' => false,
            'algorithm_version' => 'readiness-v1',
            'configuration_version' => 'config-v3',
            'calculated_at' => now(),
        ]);

        $readiness = $this->builder->build($profile)['snapshot']['readiness'];

        $this->assertTrue($readiness['available']);
        // Exactly the stored value — the builder does not recalculate.
        $this->assertSame(72.5, $readiness['score']);
        $this->assertSame('near_ready', $readiness['band']);
        $this->assertSame('config-v3', $readiness['configuration_version']);
    }

    public function test_skill_gaps_are_read_from_the_latest_successful_decision(): void
    {
        [, $profile, $role] = $this->createLearner(withRole: true);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);

        $snapshot = DecisionSnapshot::forceCreate([
            'decision_uuid' => (string) Str::uuid(),
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'algorithm_version' => 'skill-gap-v1',
            'configuration_version' => 'config-v1',
            'request_id' => 'req-1',
            'snapshot' => [],
            'status' => 'succeeded',
            'calculated_at' => now(),
        ]);

        SkillGapResult::forceCreate([
            'decision_snapshot_id' => $snapshot->id,
            'skill_id' => $skill->id,
            'current_level' => 2.0,
            'required_level' => 4.0,
            'gap' => 2.0,
            'match_score' => 50.0,
            'importance_weight' => 0.45,
            'is_critical' => true,
            'status' => 'gap',
            'explanation' => 'Stored explanation.',
        ]);

        $gaps = $this->builder->build($profile)['snapshot']['skill_gaps'];

        $this->assertTrue($gaps['available']);
        $this->assertCount(1, $gaps['gaps']);
        $this->assertSame(2.0, $gaps['gaps'][0]['gap']);
        $this->assertSame('Stored explanation.', $gaps['gaps'][0]['explanation']);
    }

    // ------------------------------------- authorization scoping (BR-11)

    public function test_another_learners_readiness_is_never_included(): void
    {
        [, $mine] = $this->createLearner();
        [, $theirs, $theirRole] = $this->createLearner(withRole: true);

        ReadinessResult::forceCreate([
            'student_profile_id' => $theirs->id,
            'career_role_id' => $theirRole->id,
            'career_role_version' => 1,
            'score' => 99.0,
            'band' => 'highly_ready',
            'algorithm_version' => 'readiness-v1',
            'calculated_at' => now(),
        ]);

        $readiness = $this->builder->build($mine)['snapshot']['readiness'];

        // The other learner has readiness; I do not. I must see "unknown",
        // not their score.
        $this->assertFalse($readiness['available']);
    }

    public function test_an_owned_recommendation_is_returned(): void
    {
        [$user, $profile] = $this->createLearner();
        $recommendation = $this->createRecommendation($user);

        $found = $this->builder->findOwnedRecommendation($profile, $recommendation->id);

        $this->assertNotNull($found);
        $this->assertSame($recommendation->id, $found->id);
    }

    public function test_another_learners_recommendation_is_not_returned(): void
    {
        [, $profile] = $this->createLearner();
        [$otherUser] = $this->createLearner();

        $theirs = $this->createRecommendation($otherUser);

        $this->assertNull($this->builder->findOwnedRecommendation($profile, $theirs->id));
    }

    public function test_a_project_with_no_connection_to_the_learner_is_not_accessible(): void
    {
        [$user, $profile] = $this->createLearner();
        $project = $this->createProject($user);

        $this->assertNull($this->builder->findAccessibleProject($profile, $project->id));
    }

    public function test_a_project_the_learner_applied_to_is_accessible(): void
    {
        [$user, $profile] = $this->createLearner();
        $project = $this->createProject($user);

        Application::forceCreate([
            'project_id' => $project->id,
            'applicant_id' => $user->id,
            'status' => 'submitted',
        ]);

        $found = $this->builder->findAccessibleProject($profile, $project->id);

        $this->assertNotNull($found);
        $this->assertSame($project->id, $found->id);
    }

    public function test_a_project_recommended_to_the_learner_is_accessible(): void
    {
        [$user, $profile] = $this->createLearner();
        $project = $this->createProject($user);

        Recommendation::forceCreate([
            'user_id' => $user->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'eligibility_state' => 'eligible',
            'generated_at' => now(),
        ]);

        $this->assertNotNull($this->builder->findAccessibleProject($profile, $project->id));
    }

    public function test_an_application_by_another_learner_does_not_grant_access(): void
    {
        [, $profile] = $this->createLearner();
        [$otherUser] = $this->createLearner();

        $project = $this->createProject($otherUser);

        Application::forceCreate([
            'project_id' => $project->id,
            'applicant_id' => $otherUser->id,
            'status' => 'submitted',
        ]);

        $this->assertNull($this->builder->findAccessibleProject($profile, $project->id));
    }

    // ------------------------------------------------------ roadmap / NBA

    public function test_the_next_best_action_skips_completed_actions(): void
    {
        [, $profile, $role] = $this->createLearner(withRole: true);

        $roadmap = Roadmap::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'version' => 1,
            'status' => 'active',
            'generated_at' => now(),
        ]);

        RoadmapAction::forceCreate([
            'roadmap_id' => $roadmap->id,
            'phase' => 'foundations',
            'type' => 'practice',
            'title' => 'Already done',
            'order_index' => 1,
            'status' => 'completed',
        ]);

        RoadmapAction::forceCreate([
            'roadmap_id' => $roadmap->id,
            'phase' => 'core_skills',
            'type' => 'resource',
            'title' => 'Do this next',
            'order_index' => 2,
            'status' => 'not_started',
        ]);

        $nba = $this->builder->build($profile)['snapshot']['next_best_action'];

        $this->assertTrue($nba['available']);
        $this->assertSame('Do this next', $nba['title']);
        // The selection basis is declared, not implied.
        $this->assertNotEmpty($nba['selection_basis']);
    }

    public function test_the_next_best_action_is_unavailable_when_everything_is_complete(): void
    {
        [, $profile, $role] = $this->createLearner(withRole: true);

        $roadmap = Roadmap::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'version' => 1,
            'status' => 'active',
            'generated_at' => now(),
        ]);

        RoadmapAction::forceCreate([
            'roadmap_id' => $roadmap->id,
            'phase' => 'foundations',
            'type' => 'practice',
            'title' => 'Done',
            'order_index' => 1,
            'status' => 'completed',
        ]);

        $nba = $this->builder->build($profile)['snapshot']['next_best_action'];

        $this->assertFalse($nba['available']);
    }

    // -------------------------------------------------------- hygiene

    public function test_the_snapshot_carries_no_personal_data(): void
    {
        [$user, $profile] = $this->createLearner();

        $snapshot = $this->builder->build($profile)['snapshot'];
        $serialised = (string) json_encode($snapshot);

        // §12.5 / §8.6: validated decision data only — no PII.
        $this->assertStringNotContainsString((string) $user->email, $serialised);
        $this->assertStringNotContainsString((string) $user->name, $serialised);
    }

    public function test_the_context_reference_is_stable_for_identical_data(): void
    {
        [, $profile] = $this->createLearner();

        $first = $this->builder->build($profile);
        $second = $this->builder->build($profile);

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertStringStartsWith('ctx-', $first['reference']);
    }

    public function test_the_context_reference_changes_when_the_underlying_data_changes(): void
    {
        [, $profile, $role] = $this->createLearner(withRole: true);

        $before = $this->builder->build($profile)['reference'];

        ReadinessResult::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'score' => 50.0,
            'band' => 'developing',
            'algorithm_version' => 'readiness-v1',
            'calculated_at' => now(),
        ]);

        $this->assertNotSame($before, $this->builder->build($profile)['reference']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{0: User, 1: StudentProfile, 2: ?CareerRole}
     */
    private function createLearner(bool $withRole = false): array
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        $role = null;

        if ($withRole) {
            $role = CareerRole::forceCreate([
                'title' => 'Data Analyst',
                'slug' => 'data-analyst-'.uniqid(),
                'version' => 1,
                'status' => 'approved',
            ]);
        }

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role?->id,
        ]);

        return [$user, $profile, $role];
    }

    private function createRecommendation(User $user): Recommendation
    {
        return Recommendation::forceCreate([
            'user_id' => $user->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => 1,
            'eligibility_state' => 'eligible',
            'generated_at' => now(),
        ]);
    }

    private function createProject(User $owner): Project
    {
        return Project::forceCreate([
            'owner_id' => $owner->id,
            'type' => 'simulation',
            'title' => 'Analytics simulation',
            'status' => 'open',
        ]);
    }
}
