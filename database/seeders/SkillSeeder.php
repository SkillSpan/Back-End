<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SkillSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $skills = [
            // General / uncategorized programming languages
            ['name' => 'Python', 'category' => null],
            ['name' => 'JavaScript', 'category' => null],
            ['name' => 'TypeScript', 'category' => null],
            ['name' => 'Java', 'category' => null],
            ['name' => 'SQL', 'category' => null],
            ['name' => 'C++', 'category' => null],
            ['name' => 'Rust', 'category' => null],

            // Web Development
            ['name' => 'React', 'category' => 'Web Development'],
            ['name' => 'Node.js', 'category' => 'Web Development'],
            ['name' => 'REST APIs', 'category' => 'Web Development'],
            ['name' => 'GraphQL', 'category' => 'Web Development'],
            ['name' => 'CSS / Tailwind', 'category' => 'Web Development'],
            ['name' => 'Next.js', 'category' => 'Web Development'],

            // Data & Analytics
            ['name' => 'Pandas', 'category' => 'Data & Analytics'],
            ['name' => 'NumPy', 'category' => 'Data & Analytics'],
        ];

        foreach ($skills as $skill) {
            Skill::updateOrCreate(
                ['slug' => Str::slug($skill['name'])],
                [
                    'name' => $skill['name'],
                    'category' => $skill['category'],
                    'status' => 'active',
                    'version' => 1,
                ]
            );
        }
    }
}
