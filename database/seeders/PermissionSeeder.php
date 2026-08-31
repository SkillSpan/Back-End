<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds the foundational permission catalogue so the RBAC subsystem has
 * data to work with. No permission is attached to any role here, and no
 * authorization logic depends on these rows yet — this only defines the
 * vocabulary for future fine-grained checks, kept idempotent so it is safe
 * to re-run.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'Manage Own Profile', 'slug' => 'profile.manage', 'group' => 'learner'],
            ['name' => 'Calculate Readiness', 'slug' => 'readiness.calculate', 'group' => 'learner'],
            ['name' => 'Manage Baseline Assessment', 'slug' => 'baseline.manage', 'group' => 'learner'],
            ['name' => 'Manage Own Organization', 'slug' => 'organization.manage', 'group' => 'organization'],
            ['name' => 'Review Organizations', 'slug' => 'organizations.review', 'group' => 'admin'],
            ['name' => 'View All Organizations', 'slug' => 'organizations.view_all', 'group' => 'admin'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                ]
            );
        }
    }
}
