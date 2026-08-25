<?php

namespace Database\Seeders;

use App\Models\Specialization;
use App\Models\University;
use Illuminate\Database\Seeder;

/**
 * Reference data for the student_profiles.university_id /
 * specialization_id Foreign Keys (learner-profile task list).
 * firstOrCreate keeps re-runs idempotent.
 */
class UniversitiesAndSpecializationsSeeder extends Seeder
{
    public function run(): void
    {
        $universities = [
            'An-Najah National University',
            'Birzeit University',
            'Islamic University of Gaza',
            'Al-Azhar University - Gaza',
            'Bethlehem University',
            'Hebron University',
            'Al-Quds University',
            'Arab American University',
            'Palestine Polytechnic University',
            'Palestine Technical University - Kadoorie',
            'Al-Quds Open University',
            'University of Palestine',
            'Israa University',
            'University College of Applied Sciences',
        ];

        foreach ($universities as $name) {
            University::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

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
