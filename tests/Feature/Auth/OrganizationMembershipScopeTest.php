<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the organization authorization scope.
 *
 * GET /api/v1/organization/profile used to resolve the organization it
 * returned with organizations()->first() while separately checking
 * whether the user administered *any* linked organization. The two
 * queries were not linked, so a user who administered organization B but
 * was only a plain member of organization A could read A's profile. The
 * pivot `status` was also ignored, so a membership marked 'removed' still
 * granted admin rights.
 */
class OrganizationMembershipScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function user(): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function organization(string $name, string $status = 'verified'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => str($name)->slug().'@test.com',
        ]);
    }

    public function test_member_of_one_org_and_admin_of_another_only_sees_the_org_they_administer(): void
    {
        $user = $this->user();

        $orgA = $this->organization('Org A');
        $orgB = $this->organization('Org B');

        // Plain member of A, administrator of B.
        OrganizationMember::forceCreate([
            'organization_id' => $orgA->id,
            'user_id' => $user->id,
            'role_in_org' => 'member',
            'status' => 'active',
        ]);
        OrganizationMember::forceCreate([
            'organization_id' => $orgB->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organization/profile');

        // The endpoint must serve the organization they actually administer.
        $response->assertOk()
            ->assertJsonPath('data.id', $orgB->id)
            ->assertJsonPath('data.name', 'Org B');

        // And must never disclose organization A's data.
        $this->assertNotSame($orgA->id, $response->json('data.id'));
        $this->assertNotSame('Org A', $response->json('data.name'));
    }

    public function test_removed_admin_membership_is_rejected(): void
    {
        $user = $this->user();
        $org = $this->organization('Removed Org');

        OrganizationMember::forceCreate([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'removed',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_invited_admin_membership_is_rejected(): void
    {
        $user = $this->user();
        $org = $this->organization('Invited Org');

        OrganizationMember::forceCreate([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'invited',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403);
    }

    public function test_plain_member_is_rejected_with_the_admin_message(): void
    {
        $user = $this->user();
        $org = $this->organization('Member Only Org');

        OrganizationMember::forceCreate([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_in_org' => 'member',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only an organization admin can view this profile.');
    }

    public function test_active_admin_membership_still_grants_access(): void
    {
        $user = $this->user();
        $org = $this->organization('Good Org');

        OrganizationMember::forceCreate([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $org->id);
    }

    /**
     * The approval middleware must consider every active membership, so an
     * approved organization cannot be used to carry a pending one through.
     */
    public function test_pending_membership_blocks_even_when_another_org_is_approved(): void
    {
        $user = $this->user();

        $approved = $this->organization('Approved Org', 'verified');
        $pending = $this->organization('Pending Org', 'pending');

        OrganizationMember::forceCreate([
            'organization_id' => $approved->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);
        OrganizationMember::forceCreate([
            'organization_id' => $pending->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403);
    }

    /**
     * A 'removed' membership must not be able to gate the approval check
     * either — it is ignored entirely.
     */
    public function test_removed_membership_does_not_gate_the_approval_check(): void
    {
        $user = $this->user();

        $approved = $this->organization('Approved Org', 'verified');
        $removedPending = $this->organization('Removed Pending Org', 'pending');

        OrganizationMember::forceCreate([
            'organization_id' => $approved->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);
        OrganizationMember::forceCreate([
            'organization_id' => $removedPending->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'removed',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organization/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $approved->id);
    }
}
