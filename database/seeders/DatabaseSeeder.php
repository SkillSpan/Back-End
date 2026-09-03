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
        // must exist before the university import runs.
        $this->call([
            RolesSeeder::class,
            CountrySeeder::class,
            UniversitySeeder::class,
            SpecializationsSeeder::class,
        ]);
    }
}
