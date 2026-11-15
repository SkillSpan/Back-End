<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function organization(array $attributes = []): Organization
    {
        return Organization::forceCreate(array_merge([
            'name' => 'Acme',
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => uniqid('acme-').'@example.com',
        ], $attributes));
    }

    private function member(Organization $organization, string $roleInOrg = 'admin'): User
    {
        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('company-admin-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->companyAdminRole->id, ['organization_id' => $organization->id]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => $roleInOrg,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_a_company_admin_deactivates_the_organization(): void
    {
        $organization = $this->organization();
        $admin = $this->member($organization);

        $admin->createToken('device');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/organizations/{$organization->id}/deactivate", [
            'reason' => 'We stopped hiring this year.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.members_suspended', 1)
            ->assertJsonPath('data.deactivation_reason', 'We stopped hiring this year.')
            ->assertJsonPath('data.redirect_url', '/login');

        $fresh = $organization->fresh();

        $this->assertNotNull($fresh->deactivated_at);
        $this->assertSame('We stopped hiring this year.', $fresh->deactivation_reason);
        $this->assertSame('suspended', $admin->fresh()->status);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_the_reason_is_optional_and_limited_to_500_characters(): void
    {
        $organization = $this->organization();
        $admin = $this->member($organization);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/organizations/{$organization->id}/deactivate", [
            'reason' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertNull($organization->fresh()->deactivated_at);

        $this->postJson("/api/v1/organizations/{$organization->id}/deactivate")
            ->assertStatus(200);

        $this->assertNotNull($organization->fresh()->deactivated_at);
        $this->assertNull($organization->fresh()->deactivation_reason);
    }

    public function test_an_admin_cannot_deactivate_an_organization_they_do_not_manage(): void
    {
        $mine = $this->organization(['name' => 'Mine']);
        $theirs = $this->organization(['name' => 'Theirs']);

        $admin = $this->member($mine);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/organizations/{$theirs->id}/deactivate", [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_MISMATCH');

        $this->assertNull($theirs->fresh()->deactivated_at);
    }

    public function test_a_learner_cannot_deactivate_an_organization(): void
    {
        $organization = $this->organization();

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $learner->roles()->attach($this->learnerRole->id);

        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/organizations/{$organization->id}/deactivate", [])
            ->assertStatus(403);

        $this->assertNull($organization->fresh()->deactivated_at);
    }

    public function test_a_deactivated_organization_is_blocked_on_company_routes(): void
    {
        $organization = $this->organization(['deactivated_at' => now()]);
        $admin = $this->member($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/profile/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ORGANIZATION_DEACTIVATED');
    }
}
