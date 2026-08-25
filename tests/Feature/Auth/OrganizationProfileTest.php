<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/organization/profile — self-service endpoint behind
 * 'auth:sanctum' + 'organization.approved'. Complements
 * OrganizationApprovalTest (which proves pending/rejected orgs are
 * blocked at the middleware layer) by asserting what a VERIFIED org
 * actually receives, plus the graceful handling of accounts that have
 * no organization at all.
 */
class OrganizationProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function user(string $role = 'learner'): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user;
    }

    public function test_verified_organization_retrieves_its_profile(): void
    {
        $member = $this->user();

        $org = Organization::create([
            'name' => 'SkillUp Ltd',
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => 'contact@skillup.com',
            'contact_phone' => '+970599000000',
            'website' => 'https://skillup.com',
            'industry' => 'EdTech',
            'company_size' => '50-100',
            'country' => 'Palestine',
            'city' => 'Nablus',
        ]);
        $org->members()->attach($member->id, ['role_in_org' => 'admin']);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/organization/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $org->id)
            ->assertJsonPath('data.name', 'SkillUp Ltd')
            ->assertJsonPath('data.type', 'company')
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.contact_email', 'contact@skillup.com')
            ->assertJsonPath('data.industry', 'EdTech')
            ->assertJsonPath('data.country', 'Palestine')
            ->assertJsonPath('data.city', 'Nablus');
    }

    /**
     * The middleware only gates accounts WITH an organization; an account
     * without one passes straight through to the controller. It must get
     * a clean 403 JSON response there — not a fatal null-pointer 500.
     */
    public function test_account_without_any_organization_gets_a_clean_403(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/organization/profile')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account is not linked to any organization.');
    }
}
