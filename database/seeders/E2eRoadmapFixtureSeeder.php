<?php

namespace Database\Seeders;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\CareerRoleSkillDependency;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dedicated, DETERMINISTIC fixtures for the Roadmap v1 Production E2E.
 *
 * Two isolated learners are created; nothing else in the database is
 * touched. The seeder is idempotent — re-running it never duplicates rows
 * and never mutates another learner's data.
 *
 *   #1 primary  — real skill gaps + real prerequisite relationships +
 *                 weekly_availability_hours > 0, so FastAPI should return
 *                 phases/actions with totals, limitations and a next best
 *                 action.
 *   #2 all-met  — every role skill already met (no gap), so FastAPI should
 *                 return no actions, estimated_total_hours = 0,
 *                 estimated_duration_weeks = 0 and next_best_action_id null.
 *
 * Test-only: it never runs in production. It does NOT use `prerequisite_skill_id`
 * (the legacy single-prerequisite column) — prerequisites are created through
 * the real `career_role_skill_dependencies` relation.
 */
class E2eRoadmapFixtureSeeder extends Seeder
{
    private const PRIMARY_EMAIL = 'e2e.roadmap.primary@skillspan.local';

    private const ALL_MET_EMAIL = 'e2e.roadmap.allmet@skillspan.local';

    /**
     * Deterministic role skills: [name, required_level, importance_weight,
     * is_critical, current_level].
     *
     * @var list<array{0: string, 1: float, 2: float, 3: bool, 4: float}>
     */
    private const PRIMARY_SKILLS = [
        ['SQL', 4.0, 0.40, true, 2.0],
        ['Python', 4.0, 0.30, true, 3.0],
        ['Power BI', 4.0, 0.20, false, 4.0],
        ['Statistics', 4.0, 0.10, false, 2.5],
    ];

    /**
     * Every skill already met (current == required) — no gap at all.
     *
     * @var list<array{0: string, 1: float, 2: float, 3: bool, 4: float}>
     */
    private const ALL_MET_SKILLS = [
        ['SQL', 4.0, 0.40, true, 4.0],
        ['Python', 4.0, 0.30, true, 4.0],
        ['Power BI', 4.0, 0.20, false, 4.0],
        ['Statistics', 4.0, 0.10, false, 4.0],
    ];

    private const WEEKLY_AVAILABILITY_HOURS = 20.0;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->ensureActiveAlgorithmConfiguration();

        $learnerRole = Role::firstOrCreate(
            ['slug' => 'learner'],
            ['name' => 'Learner', 'description' => 'Student or Graduate'],
        );

        // #1 — SQL and Python have real gaps; Power BI is already met.
        //      SQL declares Python and Statistics as its prerequisites.
        $this->seedLearner(
            $learnerRole,
            self::PRIMARY_EMAIL,
            'E2E Roadmap Primary',
            'e2e-roadmap-primary',
            'E2E Roadmap Primary Role',
            self::PRIMARY_SKILLS,
            ['SQL' => ['Python', 'Statistics']],
        );

        // #2 — every role skill is already at the required level.
        $this->seedLearner(
            $learnerRole,
            self::ALL_MET_EMAIL,
            'E2E Roadmap All Met',
            'e2e-roadmap-allmet',
            'E2E Roadmap All Met Role',
            self::ALL_MET_SKILLS,
            [],
        );
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float, 3: bool, 4: float}>  $skillData
     * @param  array<string, list<string>>  $prerequisites  skill name => prerequisite skill names
     */
    private function seedLearner(
        Role $learnerRole,
        string $email,
        string $userName,
        string $roleSlug,
        string $roleTitle,
        array $skillData,
        array $prerequisites,
    ): void {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $userName,
                'password' => Hash::make('password123'),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $user->roles()->syncWithoutDetaching([$learnerRole->id]);

        $role = CareerRole::updateOrCreate(
            ['slug' => $roleSlug, 'version' => 1],
            [
                'title' => $roleTitle,
                'status' => 'approved',
                'effective_date' => now()->toDateString(),
                'description' => 'Deterministic E2E fixture role for the Roadmap v1 contract.',
            ],
        );

        $profile = StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'availability' => 'evenings',
                'weekly_availability_hours' => self::WEEKLY_AVAILABILITY_HOURS,
                'primary_career_role_id' => $role->id,
                'consent_given' => true,
            ],
        );

        $roleSkills = [];

        foreach ($skillData as [$name, $requiredLevel, $weight, $isCritical, $currentLevel]) {
            $skill = Skill::firstOrCreate(
                ['name' => $name],
                ['slug' => strtolower(str_replace(' ', '-', $name))],
            );

            $roleSkills[$name] = CareerRoleSkill::updateOrCreate(
                ['career_role_id' => $role->id, 'skill_id' => $skill->id],
                [
                    'required_level' => $requiredLevel,
                    'importance_weight' => $weight,
                    'is_critical' => $isCritical,
                ],
            );

            SkillEvaluation::updateOrCreate(
                [
                    'student_profile_id' => $profile->id,
                    'skill_id' => $skill->id,
                    'algorithm_version' => 'e2e-baseline-v1',
                ],
                [
                    'level' => $currentLevel,
                    'confidence' => 90,
                    'calculated_at' => now(),
                    'snapshot' => ['source' => 'E2eRoadmapFixtureSeeder'],
                ],
            );
        }

        foreach ($prerequisites as $skillName => $prerequisiteSkillNames) {
            foreach ($prerequisiteSkillNames as $prerequisiteSkillName) {
                CareerRoleSkillDependency::firstOrCreate([
                    'career_role_skill_id' => $roleSkills[$skillName]->id,
                    'prerequisite_skill_id' => $roleSkills[$prerequisiteSkillName]->skill_id,
                ]);
            }
        }
    }

    private function ensureActiveAlgorithmConfiguration(): void
    {
        $exists = AlgorithmConfiguration::query()
            ->where('name', 'intelligence')
            ->where('status', 'active')
            ->exists();

        if ($exists) {
            return;
        }

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => [
                'skill_scale' => [0, 5],
                'readiness_scale' => [0, 100],
                'importance_weight_scale' => [0, 1],
                'critical_skill_cap' => ['enabled' => true, 'factor' => 0.7],
                'pending_evidence_confidence_factor' => 0.5,
            ],
            'created_by' => null,
            'activated_at' => now(),
        ]);
    }
}
