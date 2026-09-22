<?php

namespace Tests\Feature\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectMatchingSnapshotService as SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMatchingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private SnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SnapshotService();

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

    // --------------------------------------------------------------- tests

    public function test_valid_authorized_available_and_eligible_project_creates_snapshot(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->service->createForProject($project, $learner);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->exists);
        $this->assertSame((int) $project->id, $snapshot->project_id);
        $this->assertSame((int) $learner->studentProfile->id, $snapshot->student_profile_id);
        $this->assertSame('validated', $snapshot->status);
    }

    public function test_unavailable_project_cannot_create_snapshot(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject(['status' => 'closed']);

        $this->expectException(IntelligenceException::class);
        $this->expectExceptionCode(0);

        try {
            $this->service->createForProject($project, $learner);
        } catch (IntelligenceException $e) {
            $this->assertSame('PROJECT_MATCH_UNAVAILABLE', $e->codeName);
            throw $e;
        }
    }

    public function test_unauthorized_learner_cannot_create_snapshot(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject(['confidentiality' => 'restricted']);

        $this->expectException(IntelligenceException::class);

        try {
            $this->service->createForProject($project, $learner);
        } catch (IntelligenceException $e) {
            $this->assertSame('PROJECT_MATCH_UNAUTHORIZED', $e->codeName);
            throw $e;
        }
    }

    public function test_learner_without_student_profile_is_rejected(): void
    {
        $user = User::forceCreate([
            'name' => 'No Profile',
            'email' => 'noprofile@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        $project = $this->createOpenProject();

        $this->expectException(IntelligenceException::class);

        try {
            $this->service->createForProject($project, $user);
        } catch (IntelligenceException $e) {
            $this->assertSame('PROJECT_MATCH_NO_STUDENT_PROFILE', $e->codeName);
            throw $e;
        }
    }

    public function test_ineligible_learner_cannot_create_snapshot(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        // Learner has no skill evaluation at all — ineligible.
        $this->expectException(IntelligenceException::class);

        try {
            $this->service->createForProject($project, $learner);
        } catch (IntelligenceException $e) {
            $this->assertSame('PROJECT_MATCH_INELIGIBLE', $e->codeName);
            throw $e;
        }
    }

    public function test_project_version_is_captured(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject(['version' => 5]);

        $snapshot = $this->service->createForProject($project, $learner);

        $this->assertSame(5, $snapshot->project_version);
        $this->assertSame(5, $snapshot->snapshot['project']['version']);
    }

    public function test_required_project_skills_are_represented(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill1 = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        $skill2 = Skill::create(['name' => 'Docker', 'slug' => 'docker', 'category' => 'devops']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill1->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill2->id,
            'minimum_level' => 2.0,
            'is_critical_entry' => false,
        ]);

        $this->addSkillEvaluation($learner, $skill1, 4.0);
        $this->addSkillEvaluation($learner, $skill2, 3.0);

        $snapshot = $this->service->createForProject($project, $learner);

        $requiredSkills = $snapshot->snapshot['required_skills'];
        $this->assertCount(2, $requiredSkills);

        $phpSkill = collect($requiredSkills)->firstWhere('skill_id', $skill1->id);
        $this->assertNotNull($phpSkill);
        $this->assertEquals(3.0, $phpSkill['minimum_level']);
        $this->assertTrue($phpSkill['is_critical_entry']);

        $dockerSkill = collect($requiredSkills)->firstWhere('skill_id', $skill2->id);
        $this->assertNotNull($dockerSkill);
        $this->assertFalse($dockerSkill['is_critical_entry']);
    }

    public function test_critical_flags_and_required_levels_are_preserved(): void
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

        $snapshot = $this->service->createForProject($project, $learner);

        $required = collect($snapshot->snapshot['required_skills'])->firstWhere('skill_id', $skill->id);
        $this->assertTrue($required['is_critical_entry']);
        $this->assertEquals(4.5, $required['minimum_level']);

        $learnerSkill = collect($snapshot->snapshot['learner_skills'])->firstWhere('skill_id', $skill->id);
        $this->assertEquals(5.0, $learnerSkill['current_level']);
        $this->assertTrue($learnerSkill['meets_requirement']);
    }

    public function test_learner_skill_context_uses_authoritative_evaluations(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $skill = Skill::create(['name' => 'Go', 'slug' => 'go', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 4.0);

        $snapshot = $this->service->createForProject($project, $learner);

        $learnerSkill = collect($snapshot->snapshot['learner_skills'])->firstWhere('skill_id', $skill->id);
        $this->assertEquals(4.0, $learnerSkill['current_level']);
        $this->assertEquals(3.0, $learnerSkill['required_level']);
        $this->assertTrue($learnerSkill['meets_requirement']);
    }

    public function test_algorithm_version_is_captured(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->service->createForProject($project, $learner);

        $this->assertEquals(1, $snapshot->algorithm_version);
        $this->assertSame('1', $snapshot->algorithm_version);
        $this->assertSame('1', $snapshot->snapshot['algorithm_version']);
    }

    public function test_configuration_version_is_captured(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot = $this->service->createForProject($project, $learner);

        $this->assertSame('project-matching-v1', $snapshot->configuration_version);
    }

    public function test_repeated_identical_input_produces_equivalent_snapshot(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        $snapshot1 = $this->service->createForProject($project, $learner);
        $snapshot2 = $this->service->createForProject($project, $learner);

        $this->assertNotEquals($snapshot1->id, $snapshot2->id);
        $this->assertSame($snapshot1->project_id, $snapshot2->project_id);
        $this->assertSame($snapshot1->project_version, $snapshot2->project_version);
        $this->assertSame($snapshot1->algorithm_version, $snapshot2->algorithm_version);
        $this->assertSame($snapshot1->configuration_version, $snapshot2->configuration_version);
        $this->assertSame($snapshot1->snapshot, $snapshot2->snapshot);
    }

    public function test_existing_career_role_decision_snapshot_behavior_still_works(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createOpenProject();

        // Ensure the project matching snapshot creates successfully.
        $snapshot = $this->service->createForProject($project, $learner);
        $this->assertTrue($snapshot->exists);

        // Ensure no career-role DecisionSnapshot is created.
        $this->assertSame(0, \App\Models\DecisionSnapshot::count());
    }
}
