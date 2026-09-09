<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CareerRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Requires SkillSeeder to have run first (skills must exist to link).
        $roles = [
            [
                'title' => 'Frontend Developer',
                'skills' => [
                    'JavaScript' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'React' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true, 'prerequisites' => ['JavaScript']],
                    'CSS / Tailwind' => ['required_level' => 2.5, 'importance_weight' => 0.6, 'is_critical' => false],
                    'TypeScript' => ['required_level' => 2.0, 'importance_weight' => 0.5, 'is_critical' => false, 'prerequisites' => ['JavaScript']],
                ],
            ],
            [
                'title' => 'Backend Developer',
                'skills' => [
                    'Node.js' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true, 'prerequisites' => ['JavaScript']],
                    'SQL' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true],
                    'REST APIs' => ['required_level' => 3.0, 'importance_weight' => 0.7, 'is_critical' => false],
                ],
            ],
            [
                'title' => 'Data Analyst',
                'skills' => [
                    'Python' => ['required_level' => 3.0, 'importance_weight' => 0.9, 'is_critical' => true],
                    'Pandas' => ['required_level' => 3.0, 'importance_weight' => 0.8, 'is_critical' => true, 'prerequisites' => ['Python']],
                    'SQL' => ['required_level' => 2.5, 'importance_weight' => 0.7, 'is_critical' => false],
                    'NumPy' => ['required_level' => 2.0, 'importance_weight' => 0.5, 'is_critical' => false, 'prerequisites' => ['Python']],
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $careerRole = CareerRole::updateOrCreate(
                ['slug' => Str::slug($roleData['title']), 'version' => 1],
                [
                    'title' => $roleData['title'],
                    'status' => 'approved',
                    'effective_date' => now()->toDateString(),
                ]
            );

            foreach ($roleData['skills'] as $skillName => $pivot) {
                $skill = Skill::where('slug', Str::slug($skillName))->first();

                if (! $skill) {
                    continue; // Skip silently if SkillSeeder hasn't created it yet.
                }

                $prerequisiteNames = $pivot['prerequisites'] ?? [];
                unset($pivot['prerequisites']);

                $roleSkill = $careerRole->roleSkills()->updateOrCreate(
                    ['skill_id' => $skill->id],
                    $pivot
                );

                if ($prerequisiteNames !== []) {
                    $prerequisiteIds = Skill::whereIn('slug', array_map(fn ($n) => Str::slug($n), $prerequisiteNames))
                        ->pluck('id');

                    $roleSkill->prerequisites()->sync($prerequisiteIds);
                }
            }
        }
    }
}
