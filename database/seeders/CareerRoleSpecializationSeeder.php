<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Links prototype specializations to their relevant career roles via the
 * career_role_specialization pivot.
 *
 * Specializations are resolved by name and career roles by slug — never by
 * hard-coded ids. syncWithoutDetaching() keeps the seed idempotent (no
 * duplicate pairs) and additive (it never removes a link that already
 * exists).
 */
class CareerRoleSpecializationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $map = [
            'Computer Science' => ['backend-developer', 'data-analyst', 'ai-engineer'],
            'Software Engineering' => ['frontend-developer', 'backend-developer', 'mobile-developer'],
            'Information Technology' => ['it-support-specialist', 'network-administrator', 'system-administrator'],
            'Information Systems' => ['business-analyst', 'systems-analyst', 'data-analyst'],
            'Data Science' => ['data-analyst', 'data-scientist', 'machine-learning-engineer'],
            'Artificial Intelligence' => ['ai-engineer', 'machine-learning-engineer', 'nlp-engineer'],
            'Cybersecurity' => ['security-analyst', 'soc-analyst', 'penetration-tester'],
            'Computer Engineering' => ['embedded-systems-developer', 'iot-developer', 'backend-developer'],
        ];

        foreach ($map as $specializationName => $roleSlugs) {
            $specialization = Specialization::where('name', $specializationName)->first();

            if (! $specialization) {
                continue; // Skip if SpecializationsSeeder hasn't created it yet.
            }

            $careerRoleIds = CareerRole::whereIn('slug', $roleSlugs)->pluck('id')->all();

            if ($careerRoleIds === []) {
                continue;
            }

            $specialization->careerRoles()->syncWithoutDetaching($careerRoleIds);
        }
    }
}
