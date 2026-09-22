<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectEligibilityService();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createLearnerWithProfile(): User
    {
        $org = Organization::create(['name' => 'Test Org', 'type' => 'company']);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);
        $user->organizations()->attach($org->id, ['role_in_org' => 'member', 'status' => 'active']);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->load('studentProfile');
    }

    private function createProject(array $attributes = []): Project
    {
        $org = Organization::first() ?? Organization::create(['name' => 'Test Org', 'type' => 'company']);

        return Project::create(array_merge([
            'organization_id' => $org->id,
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
        ], $attributes));
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

    public function test_eligible_learner_with_all_required_critical_skills(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 4.0);

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
        $this->assertEmpty($result->reasons);
        $this->assertEmpty($result->skill_failures);
    }

    public function test_learner_missing_a_critical_required_skill(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill1 = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        $skill2 = Skill::create(['name' => 'JavaScript', 'slug' => 'js', 'category' => 'frontend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill1->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill2->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill1, 4.0);

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertNotEmpty($result->reasons);
        $this->assertCount(1, $result->skill_failures);
        $this->assertEquals($skill2->id, $result->skill_failures[0]['skill_id']);
    }

    public function test_learner_with_critical_skill_level_below_required(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill = Skill::create(['name' => 'Python', 'slug' => 'python', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 2.5);

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertCount(1, $result->skill_failures);
        $this->assertEquals(4.0, $result->skill_failures[0]['required_level']);
        $this->assertEquals(2.5, $result->skill_failures[0]['learner_level']);
    }

    public function test_learner_with_multiple_failed_critical_skills(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill1 = Skill::create(['name' => 'Java', 'slug' => 'java', 'category' => 'backend']);
        $skill2 = Skill::create(['name' => 'Go', 'slug' => 'go', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill1->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill2->id,
            'minimum_level' => 3.5,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill1, 2.0);
        $this->addSkillEvaluation($learner, $skill2, 1.0);

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertCount(2, $result->skill_failures);
    }

    public function test_project_with_no_critical_skills_is_eligible(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill = Skill::create(['name' => 'Python', 'slug' => 'python', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => false,
        ]);

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
        $this->assertEmpty($result->skill_failures);
    }

    public function test_non_critical_skill_does_not_block_eligibility(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $criticalSkill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
        $nonCriticalSkill = Skill::create(['name' => 'Docker', 'slug' => 'docker', 'category' => 'devops']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $criticalSkill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $nonCriticalSkill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => false,
        ]);

        $this->addSkillEvaluation($learner, $criticalSkill, 4.0);

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
        $this->assertEmpty($result->skill_failures);
    }

    public function test_learner_with_duplicate_active_application_is_ineligible(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $application = Application::create([
            'project_id' => $project->id,
            'applicant_id' => $learner->id,
        ]);
        $application->status = 'submitted';
        $application->save();

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('active application', $result->reasons[0]);
    }

    public function test_learner_with_withdrawn_application_is_still_eligible(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $application = Application::create([
            'project_id' => $project->id,
            'applicant_id' => $learner->id,
        ]);
        $application->status = 'withdrawn';
        $application->save();

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
    }

    public function test_learner_with_active_assignment_conflict_is_ineligible(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $team = ProjectTeam::create([
            'project_id' => $project->id,
            'name' => 'Team Alpha',
        ]);

        ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $learner->id,
            'assignment_state' => 'active',
        ]);

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('active assignment', $result->reasons[0]);
    }

    public function test_unsupported_eligibility_constraint_is_not_enforced(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'location',
            'value' => 'New York',
        ]);

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'language',
            'value' => 'Arabic',
        ]);

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
    }

    public function test_work_mode_constraint_is_enforced(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $learner->studentProfile->update(['preferred_work_type' => 'remote']);

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'work_mode',
            'value' => 'onsite',
        ]);

        $result = $this->service->check($project, $learner);

        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('work_mode', $result->reasons[0]);
    }

    public function test_matching_work_mode_constraint_is_eligible(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $learner->studentProfile->update(['preferred_work_type' => 'remote']);

        ProjectEligibilityConstraint::create([
            'project_id' => $project->id,
            'constraint_type' => 'work_mode',
            'value' => 'remote',
        ]);

        $result = $this->service->check($project, $learner);

        $this->assertTrue($result->eligible);
    }

    public function test_deterministic_result_for_identical_inputs(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => true,
        ]);

        $this->addSkillEvaluation($learner, $skill, 4.0);

        $result1 = $this->service->check($project, $learner);
        $result2 = $this->service->check($project, $learner);

        $this->assertEquals($result1->eligible, $result2->eligible);
        $this->assertEquals($result1->reasons, $result2->reasons);
        $this->assertEquals($result1->skill_failures, $result2->skill_failures);
    }

    public function test_is_eligible_returns_bool(): void
    {
        $learner = $this->createLearnerWithProfile();
        $project = $this->createProject();

        $this->assertTrue($this->service->isEligible($project, $learner));

        $application = Application::create([
            'project_id' => $project->id,
            'applicant_id' => $learner->id,
        ]);
        $application->status = 'submitted';
        $application->save();

        $this->assertFalse($this->service->isEligible($project, $learner));
    }
}
