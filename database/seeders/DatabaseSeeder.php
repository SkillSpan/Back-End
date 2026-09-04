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
<<<<<<< HEAD
            PermissionSeeder::class,
            UniversitiesAndSpecializationsSeeder::class,
=======
            CountrySeeder::class,
            UniversitySeeder::class,
            SpecializationsSeeder::class,
>>>>>>> eccb780a35e02802e04a70c199f70ef6f45147d8
        ]);
    }
}
