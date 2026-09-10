<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Order matters: universities reference countries, so countries
        // must exist before the university import runs. The algorithm
        // configuration must be active before any intelligence
        // calculation (US-INT-01) can bind a decision to a version.
        $this->call([
            RolesSeeder::class,
            PermissionSeeder::class,
            CountrySeeder::class,
            UniversitySeeder::class,
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            BaselineAssessmentItemSeeder::class,
            AlgorithmConfigurationSeeder::class,
        ]);
    }
}
