<?php

namespace Database\Seeders;

use App\Models\Specialization;
use Illuminate\Database\Seeder;

/**
 * Specialization reference data for the autocomplete dropdowns.
 * Extracted as-is from the former UniversitiesAndSpecializationsSeeder
 * when university seeding moved to the Hipolabs-backed UniversitySeeder;
 * firstOrCreate keeps re-runs idempotent.
 */
class SpecializationsSeeder extends Seeder
{
    public function run(): void
    {
        $specializations = [
            'Computer Science',
            'Software Engineering',
            'Information Systems',
            'Information Technology',
            'Data Science',
            'Artificial Intelligence',
            'Cybersecurity',
            'Computer Engineering',
            'Electrical Engineering',
            'Mechanical Engineering',
            'Civil Engineering',
            'Business Administration',
            'Accounting',
            'Marketing',
            'Graphic Design',
            'Nursing',
            'Pharmacy',
            'Law',
        ];

        foreach ($specializations as $name) {
            Specialization::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
