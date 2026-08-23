<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the security gap reported this morning: a pending/rejected
 * organization account must never obtain authenticated access, whether
 * through /api/auth/login, /api/auth/login/organization, or directly
 * against a protected organization API using a token obtained some
 * other way.
 */
class OrganizationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function createOrgUser(string $verificationStatus, bool $emailVerified = true): User
    {
        $user = User::create([
            'name' => 'Org Admin',
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
            'status' => 'active',
            'email_verified_at' => $emailVerified ? now() : null,
        ]);

        $organization = Organization::create([
            'name' => 'Test Org',
            'type' => 'company',
            'verification_status' => $verificationStatus,
            'contact_email' => 'contact@testorg.com',
        ]);

        $role = Role::where('slug', 'company_admin')->first();
        $user->roles()->attach($role->id, ['organization_id' => $organization->id]);

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_pending_organization_with_verified_email_is_denied_login(): void
    {
        $this->createOrgUser('pending', emailVerified: true);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_pending_organization_login_is_denied(): void
    {
        $this->createOrgUser('pending');

        $response = $this->postJson('/api/auth/login/organization', [
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_rejected_organization_is_denied(): void
    {
        $this->createOrgUser('rejected');

        $genericLogin = $this->postJson('/api/auth/login', [
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
        ]);
        $genericLogin->assertStatus(422);

        $orgLogin = $this->postJson('/api/auth/login/organization', [
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
        ]);
        $orgLogin->assertStatus(422);
    }

    public function test_approved_organization_is_allowed(): void
    {
        $this->createOrgUser('verified');

        $response = $this->postJson('/api/auth/login/organization', [
            'email' => 'orgadmin@test.com',
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_direct_protected_organization_api_is_denied_before_approval(): void
    {
        $user = $this->createOrgUser('pending');

        // Simulates a token obtained through any means (not necessarily the
        // login endpoint), to prove the middleware is an independent line
        // of defense and not just a login-time check.
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(403);
    }

    public function test_direct_protected_organization_api_is_allowed_after_approval(): void
    {
        $user = $this->createOrgUser('verified');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('data.verification_status', 'verified');
    }
}
