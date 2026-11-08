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
        //
        // MvpCatalogSeeder runs LAST: it needs the full specialization and
        // career-role catalogue to exist before it can switch the MVP subset
        // on and the rest off. It only ever flips `is_active` flags, so it is
        // safe to re-run on a populated database.
        $this->call([
            RolesSeeder::class,
            PermissionSeeder::class,
            CountrySeeder::class,
            UniversitySeeder::class,
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
            AlgorithmConfigurationSeeder::class,
            MvpCatalogSeeder::class,
        ]);
    }
}
