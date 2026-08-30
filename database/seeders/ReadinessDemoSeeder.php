<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ReadinessDemoSeeder extends Seeder
{
    public function run(): void
    {
        $learnerRole = Role::firstOrCreate(
            ['slug' => 'learner'],
            ['name' => 'Learner', 'description' => 'Student or Graduate']
        );

        $user = User::firstOrCreate(
            ['email' => 'readiness.demo@skillspan.local'],
            [
                'name' => 'Readiness Demo User',
                'password' => Hash::make('password123'),
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $user->roles()->syncWithoutDetaching([$learnerRole->id]);

        $role = CareerRole::firstOrCreate(
            ['slug' => 'data-analyst-demo', 'version' => 1],
            [
                'title' => 'Data Analyst',
                'status' => 'approved',
                'effective_date' => now()->toDateString(),
                'description' => 'Demo role for readiness integration testing.',
            ]
        );

        $skillData = [
            ['name' => 'SQL', 'required_level' => 4, 'importance_weight' => 0.45, 'is_critical' => true, 'current_level' => 2.5],
            ['name' => 'Python', 'required_level' => 4, 'importance_weight' => 0.30, 'is_critical' => true, 'current_level' => 3.5],
            ['name' => 'Power BI', 'required_level' => 4, 'importance_weight' => 0.25, 'is_critical' => false, 'current_level' => 4.0],
        ];

        $profile = StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'university_name' => 'An-Najah National University',
                'student_university_number' => '202312345',
                'specialization' => 'Data Science',
                'academic_level' => 'Fourth Year',
                'career_status' => 'Student',
                'availability' => '10 hours/week',
                'preferred_work_type' => 'Remote',
                'primary_career_role_id' => $role->id,
                'consent_given' => true,
            ]
        );

        foreach ($skillData as $item) {
            $skill = Skill::firstOrCreate(
                ['name' => $item['name']],
                ['slug' => strtolower(str_replace(' ', '-', $item['name']))]
            );

            CareerRoleSkill::updateOrCreate(
                ['career_role_id' => $role->id, 'skill_id' => $skill->id],
                [
                    'required_level' => $item['required_level'],
                    'importance_weight' => $item['importance_weight'],
                    'is_critical' => $item['is_critical'],
                ]
            );

            SkillEvaluation::updateOrCreate(
                [
                    'student_profile_id' => $profile->id,
                    'skill_id' => $skill->id,
                    'algorithm_version' => 'demo-v1',
                ],
                [
                    'level' => $item['current_level'],
                    'confidence' => 90,
                    'calculated_at' => now(),
                    'snapshot' => ['source' => 'ReadinessDemoSeeder'],
                ]
            );
        }
    }
}
