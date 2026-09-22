<?php

namespace Tests\Feature\Projects;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\ProjectMatchingSnapshot;
use App\Models\ProjectRequiredSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectMatchingPayloadBuilder;
use App\Services\Projects\ProjectMatchingSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMatchingPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectMatchingPayloadBuilder $builder;
    protected ProjectMatchingSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ProjectMatchingPayloadBuilder();
        $this->snapshotService = new ProjectMatchingSnapshotService();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        AlgorithmConfiguration::create([
            'name' => 'project-matching',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    private function createLearnerWithProfile(): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
            'availability' => 'full_time',
            'preferred_work_type' => 'remote',
        ]);

        return $user->load('studentProfile');
    }

    private function createLearnerWithCareerRole(): User
    {
        $careerRole = CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-dev',
            'version' => 1,
            'status' => 'approved',
        ]);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
            'availability' => 'full_time',
            'preferred_work_type' => 'remote',
            'primary_career_role_id' => $careerRole->id,
        ]);

        return $user->load('studentProfile');
    }

    private function createOpenProject(array $attributes = []): Project
    {
        $defaults = [
            'organization_id' => null,
            'owner_id' => User::first()?->id ?? User::forceCreate([
                'name' => 'Owner',
                'email' => 'owner@test.com',
                'password' => 'password123',
                'status' => 'active',
                'email_verified_at' => now(),
            ])->id,
            'title' => 'Test Project',
            'description' => 'A test project',
            'type' => 'company_sponsored',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ];

        return Project::create(array_merge($defaults, $attributes));
    }

    private function addSkillEvaluation(User $learner, Skill $skill, float $level): SkillEvaluation
    {
        return SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $skill->id,
            'level' => $level,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);
    }

    private function makeValidatedSnapshot(User $learner, Project $project): ProjectMatchingSnapshot
    {
        $project->load(['requiredSkills.skill', 'eligibilityConstraints']);
        return $this->snapshotService->createForProject($project, $learner);
    }

    // --------------------------------------------------------------- tests

    public function test_validated_snapshot_produces_correct_payload(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('request_id', $payload);
        $this->assertArrayHasKey('algorithm_version', $payload);
        $this->assertArrayHasKey('configuration_version', $payload);
        $this->assertArrayHasKey('project_version', $payload);
        $this->assertArrayHasKey('learner', $payload);
        $this->assertArrayHasKey('project', $payload);
        $this->assertArrayHasKey('required_skills', $payload);
        $this->assertArrayHasKey('validation', $payload);
    }

    public function test_learner_context_is_mapped_correctly(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);
        $this->addSkillEvaluation($learner, $skill, 4.0);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($learner->id, $payload['learner']['user_id']);
        $this->assertEquals($learner->studentProfile->id, $payload['learner']['student_profile_id']);
        $this->assertEquals('full_time', $payload['learner']['availability']);
        $this->assertEquals('remote', $payload['learner']['preferred_work_type']);
        $this->assertArrayHasKey('current_skills', $payload['learner']);
        $this->assertCount(1, $payload['learner']['current_skills']);
        $this->assertEquals($skill->id, $payload['learner']['current_skills'][0]['skill_id']);
        $this->assertEquals(4.0, $payload['learner']['current_skills'][0]['level']);
        $this->assertTrue($payload['learner']['current_skills'][0]['meets_requirement']);
    }

    public function test_project_context_is_mapped_correctly(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($project->id, $payload['project']['id']);
        $this->assertEquals($project->version, $payload['project']['version']);
        $this->assertEquals($project->type, $payload['project']['type']);
        $this->assertEquals($project->confidentiality, $payload['project']['confidentiality']);
    }

    public function test_required_skills_are_mapped_correctly(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);
        $this->addSkillEvaluation($learner, $skill, 4.0);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertCount(1, $payload['required_skills']);
        $reqSkill = $payload['required_skills'][0];
        $this->assertEquals($skill->id, $reqSkill['skill_id']);
        $this->assertEquals('PHP', $reqSkill['skill_name']);
        $this->assertEquals(3.0, $reqSkill['minimum_level']);
        $this->assertTrue($reqSkill['is_critical_entry']);
    }

    public function test_critical_flags_are_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);
        $this->addSkillEvaluation($learner, $skill, 4.0);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertTrue($payload['required_skills'][0]['is_critical_entry']);
    }

    public function test_minimum_levels_are_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'Python', 'slug' => 'python', 'category' => 'backend']);
        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.5,
            'is_critical_entry' => true,
        ]);
        $this->addSkillEvaluation($learner, $skill, 5.0);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals(4.5, $payload['required_skills'][0]['minimum_level']);
    }

    public function test_algorithm_version_is_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($snapshot->algorithm_version, $payload['algorithm_version']);
        $this->assertSame('1', $payload['algorithm_version']);
    }

    public function test_configuration_version_is_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($snapshot->configuration_version, $payload['configuration_version']);
        $this->assertSame('project-matching-v1', $payload['configuration_version']);
    }

    public function test_project_version_is_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject(['version' => 17]);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals(17, $payload['project_version']);
        $this->assertEquals(17, $payload['project']['version']);
    }

    public function test_request_id_is_preserved(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($snapshot->request_id, $payload['request_id']);
    }

    public function test_career_role_is_included_when_available(): void
    {
        $learner = $this->createLearnerWithCareerRole();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertArrayHasKey('target_career_role', $payload['learner']);
        $this->assertEquals(
            $learner->studentProfile->primary_career_role_id,
            $payload['learner']['target_career_role']['id']
        );
    }

    public function test_missing_optional_career_role_is_handled_correctly(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertArrayNotHasKey('target_career_role', $payload['learner']);
    }

    public function test_invalid_pending_snapshot_is_rejected(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);
        $snapshot->status = ProjectMatchingSnapshot::STATUS_FAILED;
        $snapshot->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build($snapshot);
    }

    public function test_identical_snapshot_produces_identical_payload(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload1 = $this->builder->build($snapshot);
        $payload2 = $this->builder->build($snapshot);

        $this->assertEquals($payload1, $payload2);
    }

    public function test_arrays_have_deterministic_ordering(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill1 = Skill::create(['name' => 'Zeta Skill', 'slug' => 'zeta', 'category' => 'backend']);
        $skill2 = Skill::create(['name' => 'Alpha Skill', 'slug' => 'alpha', 'category' => 'frontend']);
        $skill3 = Skill::create(['name' => 'Middle Skill', 'slug' => 'middle', 'category' => 'fullstack']);

        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $skill1->id, 'minimum_level' => 3.0, 'is_critical_entry' => false]);
        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $skill2->id, 'minimum_level' => 2.0, 'is_critical_entry' => true]);
        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $skill3->id, 'minimum_level' => 4.0, 'is_critical_entry' => false]);

        $this->addSkillEvaluation($learner, $skill1, 3.0);
        $this->addSkillEvaluation($learner, $skill2, 2.0);
        $this->addSkillEvaluation($learner, $skill3, 4.0);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals($skill1->id, $payload['required_skills'][0]['skill_id']);
        $this->assertEquals($skill2->id, $payload['required_skills'][1]['skill_id']);
        $this->assertEquals($skill3->id, $payload['required_skills'][2]['skill_id']);
    }

    public function test_no_network_request_is_performed_by_the_builder(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertIsArray($payload);
    }

    public function test_validation_context_is_mapped_correctly(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertEquals('eligible', $payload['validation']['eligibility_state']);
        $this->assertEquals('validated', $payload['validation']['validation_state']);
        $this->assertArrayHasKey('constraints', $payload['validation']);
        $this->assertArrayHasKey('skill_gaps', $payload['validation']);
        $this->assertIsArray($payload['validation']['constraints']);
    }

    public function test_eligibility_constraints_are_included_when_present(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);
        $this->addSkillEvaluation($learner, $skill, 4.0);

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'work_mode',
            'value' => 'remote',
        ]);

        $snapshot = $this->makeValidatedSnapshot($learner, $project);

        $payload = $this->builder->build($snapshot);

        $this->assertNotEmpty($payload['validation']['constraints']);
    }
}
