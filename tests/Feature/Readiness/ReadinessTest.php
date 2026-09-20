<?php

namespace Tests\Feature\Readiness;

use App\Models\AlgorithmConfiguration;
use App\Models\BaselineAssessment;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\ReadinessResult;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        // US-INT-01 §10: readiness decisions now bind to an active
        // algorithm configuration — no active config means an explicit
        // error, so seed one for every legacy readiness scenario.
        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);

        // US-INT-01: the service credential is mandatory on every
        // Laravel -> FastAPI intelligence request.
        config(['services.data_science.service_token' => 'test-service-token']);
    }

    public function test_missing_service_token_blocks_fastapi_call(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        config(['services.data_science.service_token' => null]);
        Http::fake();

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DATA_SCIENCE_NOT_CONFIGURED');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => 1])
            ->assertStatus(401);
    }

    public function test_organization_cannot_use_learner_readiness_endpoint(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => 1])
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_successful_readiness_calculation_saves_result(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response($this->successResponse($profile, $careerRole, $roleSkills), 200),
        ]);

        // The endpoint answers 201 Created because a readiness_results
        // row is persisted as part of the calculation.
        $response = $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertStatus(201);

        // Weighted composite from createScenario()'s fixture data. The
        // skill_match component is FastAPI's own skill_match_score
        // (skill-match-v1) — see ReadinessService::calculate(), which reads
        // $result['skill_match_score']. The fixture derives that value from
        // these same levels and weights, so the arithmetic below holds:
        //   skill_match (79.38) * 0.65 = 51.60
        //   practical_experience (50.0, 1 of 2 full-credit projects) * 0.20 = 10.0
        //   assessment_reliability (90.0, avg confidence) * 0.10 = 9.0
        //   profile_completeness (100.0, all required fields filled) * 0.05 = 5.0
        //   total = 75.60
        $this->assertEquals(75.6, $response->json('data.score'));
        // Regression guard: the key must actually be present. assertFalse()
        // alone would pass on a missing key, because json() returns null.
        $this->assertArrayHasKey('is_provisional', $response->json('data'));
        $this->assertFalse($response->json('data.is_provisional'));
        $this->assertDatabaseHas('readiness_results', [
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'score' => 75.60,
        ]);
    }

    public function test_career_role_not_found_uses_custom_error_code(): void
    {
        [$user] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => 99999])
            ->assertStatus(404)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND');
    }

    public function test_career_role_not_approved_uses_custom_error_code(): void
    {
        [$user,, $role] = $this->createScenario('draft');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    public function test_career_role_without_skills_uses_custom_error_code(): void
    {
        $user = $this->createUserWithRole($this->learnerRole);
        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);
        $role = CareerRole::forceCreate(['title' => 'Empty Role', 'slug' => 'empty-role-'.uniqid(), 'version' => 1, 'status' => 'approved']);
        $profile->update(['primary_career_role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NO_SKILLS');
    }

    public function test_missing_skill_evaluation_blocks_fastapi_call(): void
    {
        [$user,, $careerRole, $roleSkills] = $this->createScenario();
        SkillEvaluation::query()->where('skill_id', $roleSkills[0]->skill_id)->delete();
        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertStatus(422)->assertJsonPath('code', 'ASSESSMENT_INCOMPLETE');
        Http::assertNothingSent();
    }

    public function test_missing_practical_experience_is_provisional_not_blocked(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();

        // Remove the practical-experience fixture only: no completed/accepted/
        // evaluated project for this learner, so the component is excluded
        // and weights are renormalized over the remaining components.
        Evaluation::query()->delete();
        Submission::query()->delete();

        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response($this->successResponse($profile, $careerRole, $roleSkills), 200)]);

        $response = $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.is_provisional'));
        $this->assertNull($response->json('data.practical_experience_component'));
        // skill_match(79.38*.65) + assessment_reliability(90*.10) + profile_completeness(100*.05)
        // renormalized over the remaining weight sum (.65+.10+.05=.80):
        // (51.60 + 9 + 5) / 0.80 = 82.0
        $this->assertEquals(82.0, $response->json('data.score'));
    }

    public function test_missing_baseline_assessment_is_provisional_not_blocked(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();

        BaselineAssessment::query()->where('student_profile_id', $profile->id)->delete();

        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response($this->successResponse($profile, $careerRole, $roleSkills), 200)]);

        $response = $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.is_provisional'));
        $this->assertNull($response->json('data.assessment_reliability_component'));
        // skill_match(79.38*.65) + practical_experience(50*.20) + profile_completeness(100*.05)
        // renormalized over the remaining weight sum (.65+.20+.05=.90):
        // (51.60 + 10 + 5) / 0.90 = 74.0
        $this->assertEquals(74.0, $response->json('data.score'));
    }

    public function test_latest_skill_evaluation_is_used(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        $skillId = $roleSkills[0]->skill_id;

        SkillEvaluation::forceCreate([
            'student_profile_id' => $profile->id,
            'skill_id' => $skillId,
            'level' => 1.0,
            'confidence' => 90,
            'algorithm_version' => 'old',
            'calculated_at' => now()->subDay(),
        ]);
        SkillEvaluation::forceCreate([
            'student_profile_id' => $profile->id,
            'skill_id' => $skillId,
            'level' => 4.5,
            'confidence' => 90,
            'algorithm_version' => 'new',
            'calculated_at' => now(),
        ]);

        Sanctum::actingAs($user);
        Http::fake(function ($request) use ($profile, $careerRole, $roleSkills) {
            $skills = $request->data()['skills'];
            $sql = collect($skills)->firstWhere('skill_name', $roleSkills[0]->skill->name);
            self::assertSame(4.5, (float) $sql['current_level']);

            return Http::response($this->successResponse($profile, $careerRole, $roleSkills), 200);
        });

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])->assertStatus(201);
    }

    public function test_payload_contract_matches_fastapi_scale(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(['*' => Http::response($this->successResponse($profile, $careerRole, $careerRole->roleSkills()->with('skill')->get()), 200)]);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])->assertStatus(201);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['user_id'] > 0
                && $data['target_role'] === 'Data Analyst'
                && collect($data['skills'])->pluck('importance_weight')->sort()->values()->all() === [0.25, 0.3, 0.45];
        });
    }

    public function test_invalid_fastapi_skill_result_is_rejected(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $response = $this->successResponse($profile, $careerRole, $roleSkills);
        $response['skill_results'][1]['skill_id'] = 999999;

        Http::fake(['*' => Http::response($response, 200)]);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'DATA_SCIENCE_INVALID_RESPONSE');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_422_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['detail' => 'invalid'], 422)]);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DATA_SCIENCE_VALIDATION_ERROR');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_500_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['message' => 'server error'], 500)]);

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DATA_SCIENCE_SERVICE_ERROR');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_timeout_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DATA_SCIENCE_UNAVAILABLE');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_latest_endpoint_returns_latest_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        ReadinessResult::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'career_role_version' => 1,
            'score' => 70,
            'skill_match_component' => 70,
            'practical_experience_component' => null,
            'assessment_reliability_component' => null,
            'profile_completeness_component' => null,
            'critical_cap_applied' => false,
            'band' => null,
            'algorithm_version' => 'test',
            'calculated_at' => now()->subMinute(),
            'snapshot' => [],
        ]);
        ReadinessResult::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'career_role_version' => 1,
            'score' => 82,
            'skill_match_component' => 82,
            'practical_experience_component' => null,
            'assessment_reliability_component' => null,
            'profile_completeness_component' => null,
            'critical_cap_applied' => false,
            'band' => null,
            'algorithm_version' => 'test',
            'calculated_at' => now(),
            'snapshot' => [],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/readiness/latest?career_role_id='.$careerRole->id);
        $response->assertOk();
        $this->assertEquals(82.0, $response->json('data.score'));
    }

    private function createScenario(string $status = 'approved'): array
    {
        $user = $this->createUserWithRole($this->learnerRole);
        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => $status,
        ]);

        // All Profile Completeness required_fields (see config/readiness.php)
        // filled in, so that component defaults to 100.0 in the happy path.
        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'primary_career_role_id' => $role->id,
            'university_name' => 'Test University',
            'student_university_number' => '12345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Senior',
            'career_status' => 'student',
            'interests' => ['data-analysis'],
            'availability' => 'full_time',
        ]);

        $roleSkills = collect();
        foreach (
            [
                ['SQL', 4.0, 0.45, true, 2.5],
                ['Python', 4.0, 0.30, true, 3.5],
                ['Power BI', 4.0, 0.25, false, 4.0],
            ] as [$name, $required, $weight, $critical, $current]
        ) {
            $skill = Skill::create([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            ]);
            $roleSkill = CareerRoleSkill::create([
                'career_role_id' => $role->id,
                'skill_id' => $skill->id,
                'required_level' => $required,
                'importance_weight' => $weight,
                'is_critical' => $critical,
            ]);
            SkillEvaluation::forceCreate([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'level' => $current,
                'confidence' => 90,
                'algorithm_version' => 'test-v1',
                'calculated_at' => now()->addMicroseconds(rand(1, 1000)),
            ]);
            $roleSkills->push($roleSkill->load('skill'));
        }

        $this->seedEligiblePracticalExperience($user, $profile);
        $this->seedCompletedBaselineAssessment($profile, $roleSkills);

        return [$user, $profile, $role, $roleSkills];
    }

    /**
     * Seeds exactly one fully-eligible project (Project.status=completed,
     * ProjectTeamMember.assignment_state=completed, Submission.status=accepted,
     * Evaluation.status=finalized for THAT submission) so
     * PracticalExperienceService finds eligible_count = 1, i.e. score = 50.0
     * with the default full_credit_project_count = 2.
     */
    private function seedEligiblePracticalExperience(User $user, StudentProfile $profile): void
    {
        $rubric = Rubric::create([
            'title' => 'Test Rubric',
            'version' => 1,
            'status' => 'published',
        ]);

        $project = Project::forceCreate([
            'owner_id' => $user->id,
            'type' => 'simulation',
            'title' => 'Test Project',
            'status' => 'completed',
            'rubric_id' => $rubric->id,
        ]);

        $team = ProjectTeam::create([
            'project_id' => $project->id,
            'name' => 'Team A',
        ]);

        ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $user->id,
            'assignment_state' => 'completed',
        ]);

        $submission = Submission::forceCreate([
            'project_id' => $project->id,
            'contributor_id' => $user->id,
            'status' => 'accepted',
            'submitted_at' => now(),
        ]);

        Evaluation::forceCreate([
            'project_id' => $project->id,
            'submission_id' => $submission->id,
            'evaluator_id' => $user->id,
            'rubric_id' => $rubric->id,
            'rubric_version' => 1,
            'status' => 'finalized',
        ]);
    }

    /**
     * Seeds one completed BaselineAssessment with normalized_skills confidence
     * values on the 0-100 scale (matching BaselineAssessmentService's actual
     * storage format), so AssessmentReliabilityService finds score = 90.0.
     */
    private function seedCompletedBaselineAssessment($profile, $roleSkills): void
    {
        BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'completed',
            'normalized_skills' => $roleSkills->map(fn ($roleSkill) => [
                'skill_id' => $roleSkill->skill_id,
                'slug' => $roleSkill->skill->slug,
                'name' => $roleSkill->skill->name,
                'level' => 2.5,
                'confidence' => 90,
            ])->values()->all(),
            'completed_at' => now(),
        ]);
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Build a FastAPI skill-match-v1 response that satisfies the validator
     * in ReadinessService::validateDataScienceResult() exactly.
     *
     * The contract changed in d87040e (/skill-gap -> /skill-match v1): the
     * response now carries the normalized-weight breakdown plus
     * skill_match_score, and every skill_result is cross-checked against
     * the payload Laravel actually sent. So this fixture mirrors the
     * validator's own formulas rather than inventing its own numbers:
     *
     *  - current_level comes from the learner's LATEST evaluation per skill
     *    (calculated_at desc, id desc) — the same source the payload uses.
     *  - achieved_level = current_level capped at required_level.
     *  - match_ratio   = achieved_level / required_level, clamped to 0..1.
     *  - status        = met (ratio 1.0) / partial / not_required (req 0).
     *  - skill_match_score = normalized weighted match on a 0..100 scale.
     *    This is the value ReadinessService uses as its skill_match
     *    component, so it must stay derived — a hardcoded number would
     *    silently disagree with the levels and weights below.
     */
    private function successResponse($profile, $careerRole, $roleSkills): array
    {
        $weightTotal = (float) $roleSkills->sum('importance_weight');

        $skillResults = [];
        $metSkills = 0;
        $partialSkills = 0;
        $notRequiredSkills = 0;
        $weightedAchievedTotal = 0.0;
        $weightedRequiredTotal = 0.0;

        foreach ($roleSkills as $roleSkill) {
            $currentLevel = (float) SkillEvaluation::query()
                ->where('student_profile_id', $profile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->first()
                ->level;

            $requiredLevel = (float) $roleSkill->required_level;
            $importanceWeight = (float) $roleSkill->importance_weight;

            // Over-qualification never counts beyond 100% of what the role
            // asks for, so the achieved level is capped at the required one.
            $achievedLevel = $requiredLevel > 0
                ? min($currentLevel, $requiredLevel)
                : $currentLevel;

            if ($requiredLevel > 0) {
                $matchRatio = min(max($achievedLevel / $requiredLevel, 0.0), 1.0);
                $status = $matchRatio >= 1.0 ? 'met' : 'partial';
            } else {
                $matchRatio = 0.0;
                $status = 'not_required';
            }

            if ($status === 'met') {
                $metSkills++;
            } elseif ($status === 'partial') {
                $partialSkills++;
            } else {
                $notRequiredSkills++;
            }

            $normalizedWeight = $weightTotal > 0
                ? $importanceWeight / $weightTotal
                : 0.0;

            $weightedAchievedTotal += $normalizedWeight * $achievedLevel;
            $weightedRequiredTotal += $normalizedWeight * $requiredLevel;

            $skillResults[] = [
                'skill_id' => (int) $roleSkill->skill_id,
                'skill_name' => (string) $roleSkill->skill->name,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                'importance_weight' => $importanceWeight,
                'normalized_importance_weight' => $normalizedWeight,
                'achieved_level' => $achievedLevel,
                'match_ratio' => $matchRatio,
                'is_critical' => (bool) $roleSkill->is_critical,
                'status' => $status,
            ];
        }

        $skillMatchScore = $weightedRequiredTotal > 0
            ? round($weightedAchievedTotal / $weightedRequiredTotal * 100, 2)
            : 0.0;

        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $careerRole->id,
            'career_role_version' => (int) $careerRole->version,
            'user_id' => (int) $profile->user_id,
            'target_role' => (string) $careerRole->title,
            'algorithm_version' => 'skill-match-v1',
            'weight_configuration_version' => 'weights-v1',
            'original_weight_total' => $weightTotal,
            'normalized_weight_total' => 1.0,
            'weighted_achieved_total' => $weightedAchievedTotal,
            'weighted_required_total' => $weightedRequiredTotal,
            'skill_match_score' => $skillMatchScore,
            'total_skills' => count($skillResults),
            'met_skills' => $metSkills,
            'partial_skills' => $partialSkills,
            'not_required_skills' => $notRequiredSkills,
            'skill_results' => $skillResults,
        ];
    }
}
