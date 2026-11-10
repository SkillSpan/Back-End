<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationLogoTest extends TestCase
{
    use RefreshDatabase;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('public', config('company.profile.logo.disk'));
        Storage::fake('public');

        $this->companyAdminRole = Role::create([
            'name' => 'Company Admin',
            'slug' => 'company_admin',
            'description' => '',
        ]);
    }

    private function organization(string $name = 'Acme', string $status = 'verified'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function companyAdmin(Organization $organization): User
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
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    public function test_a_company_admin_can_upload_a_logo(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $response = $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logo updated successfully.');

        $this->assertNotNull($response->json('data.logo'));
        $this->assertNotNull($response->json('data.logo_url'));

        $organization->refresh();
        $this->assertNotNull($organization->logo);
        Storage::disk('public')->assertExists($organization->logo);
    }

    public function test_the_logo_is_visible_in_the_profile_payload(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 80, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $stored = $organization->fresh()->logo;

        $response = $this->getJson('/api/v1/profile/me')->assertOk();

        $response->assertJsonPath('data.logo', $stored);
        $this->assertNotNull($response->json('data.logo_url'));
    }

    public function test_replacing_the_logo_deletes_the_previous_file(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('first.png', 60, 60),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $organization->fresh()->logo;

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('second.png', 90, 90),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $organization->fresh()->logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_non_image_is_refused(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->create('logo.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LOGO_TYPE_NOT_SUPPORTED');

        $this->assertNull($organization->fresh()->logo);
    }

    public function test_an_image_that_is_too_large_is_refused(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        config(['company.profile.logo.max_kb' => 0]);

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('big.png', 400, 400),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LOGO_TOO_LARGE');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $admin = $this->companyAdmin($this->organization());

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LOGO_MISSING');
    }

    public function test_the_logo_can_be_removed_and_removal_is_idempotent(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 70, 70),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $organization->fresh()->logo;

        $this->deleteJson('/api/v1/company/profile/logo')
            ->assertOk()
            ->assertJsonPath('data.logo', null);

        $this->assertNull($organization->fresh()->logo);
        Storage::disk('public')->assertMissing($path);

        $this->deleteJson('/api/v1/company/profile/logo')
            ->assertOk()
            ->assertJsonPath('data.logo', null);
    }

    public function test_a_learner_is_refused(): void
    {
        $learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $learner->roles()->attach($learnerRole->id);

        Sanctum::actingAs($learner);

        $this->post('/api/v1/company/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
        ], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->post('/api/v1/company/profile/logo', [], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }
}
