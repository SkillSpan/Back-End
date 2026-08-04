<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Learner', 'slug' => 'learner', 'description' => 'Student or Graduate'],
            ['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => 'Admin of a company'],
            ['name' => 'University Admin', 'slug' => 'university_admin', 'description' => 'Admin of a university'],
            ['name' => 'Super Admin', 'slug' => 'admin', 'description' => 'Platform Administrator'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'description' => $role['description']]
            );
        }
    }
}
