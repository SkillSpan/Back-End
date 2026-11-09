<?php

namespace Tests\Feature\Console;

use App\Models\BaselineAssessment;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The repair command exists because the post-assessment recalculation is
 * queued and swallows its own failures. When it aborted on
 * `CAREER_ROLE_REQUIRED`, nothing was written and nothing surfaced: the
 * learner had a completed assessment and both `latest` endpoints still
 * answered 404.
 *
 * These tests pin the two behaviours that matter — a dry run must not write
 * anything to a shared production database, and a forced run must recover the
 * role from the assessment and actually re-run the calculation.
 */
class RepairIntelligenceReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
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

    private function careerRole(): CareerRole
    {
        return CareerRole::create([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function completedAssessment(User $learner, CareerRole $role): BaselineAssessment
    {
        /*
         * `forceCreate` — `status` is not fillable, so create() would silently
         * drop it and leave the attempt in_progress, which is exactly the state
         * this command is supposed to ignore.
         */
        return BaselineAssessment::forceCreate([
            'student_profile_id' => $learner->studentProfile->id,
            'career_role_id' => $role->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'question_count' => 1,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $learner = $this->learner();
        $role = $this->careerRole();
        $this->completedAssessment($learner, $role);

        $this->mock(IntelligenceService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldNotReceive('calculate');
        });

        $this->artisan('app:repair-intelligence-readiness')
            ->expectsOutputToContain('need a repair')
            ->assertExitCode(0);

        $this->assertNull($learner->studentProfile->fresh()->primary_career_role_id);
    }

    public function test_a_forced_run_recovers_the_role_and_recalculates(): void
    {
        $learner = $this->learner();
        $role = $this->careerRole();
        $this->completedAssessment($learner, $role);

        $this->mock(IntelligenceService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('calculate')->once();
        });

        $this->artisan('app:repair-intelligence-readiness', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(
            $role->id,
            (int) $learner->studentProfile->fresh()->primary_career_role_id,
        );
    }

    public function test_a_failed_recalculation_is_reported_rather_than_swallowed(): void
    {
        $learner = $this->learner();
        $role = $this->careerRole();
        $this->completedAssessment($learner, $role);

        $this->mock(IntelligenceService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('calculate')->once()->andThrow(new \RuntimeException('service down'));
        });

        $this->artisan('app:repair-intelligence-readiness', ['--force' => true])
            ->expectsOutputToContain('Repaired 0, failed 1.')
            ->assertExitCode(1);
    }

    public function test_it_ignores_learners_without_a_completed_assessment(): void
    {
        $this->learner();

        $this->artisan('app:repair-intelligence-readiness', ['--force' => true])
            ->expectsOutputToContain('No learner with a completed assessment was found.')
            ->assertExitCode(0);
    }
}
