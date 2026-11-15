<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPanelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function admin(string $status = 'active'): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => $status,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Plain Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function mentor(): User
    {
        $user = User::forceCreate([
            'name' => 'Support Mentor',
            'email' => 'mentor@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        ProfessionalProfile::create([
            'user_id' => $user->id,
            'type' => 'mentor',
            'expertise' => 'Backend engineering',
        ]);

        return $user->fresh();
    }

    private function organization(string $status = 'pending'): Organization
    {
        return Organization::forceCreate([
            'name' => 'Test Company',
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => 'hr@company.com',
            'industry' => 'Software',
        ]);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin/organizations')->assertRedirect(route('login'));
    }

    public function test_the_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sign in');
    }

    public function test_a_signed_in_admin_can_open_the_panel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/organizations')
            ->assertOk()
            ->assertSee($admin->email);
    }

    public function test_a_learner_cannot_open_the_panel_even_while_signed_in(): void
    {
        $this->actingAs($this->learner())
            ->get('/admin/organizations')
            ->assertStatus(403);
    }

    public function test_a_suspended_admin_loses_an_existing_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/organizations')
            ->assertOk();

        $admin->forceFill(['status' => 'suspended'])->save();

        $this->get('/admin/organizations')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_api_still_gets_a_json_403_when_suspended(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($admin);

        $this->getJson('/admin/api/organizations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_an_admin_can_sign_in_with_email_and_password(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ])->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->admin();

        $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_learner_with_valid_credentials_is_refused(): void
    {
        $this->learner();

        $this->post('/admin/login', [
            'email' => 'learner@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_mentor_with_valid_credentials_can_sign_in(): void
    {
        $this->mentor();

        $this->post('/admin/login', [
            'email' => 'mentor@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('admin.support'));

        $this->assertAuthenticated();
    }

    public function test_a_mentors_landing_page_actually_opens(): void
    {
        $this->mentor();

        $landing = $this->post('/admin/login', [
            'email' => 'mentor@example.com',
            'password' => 'password123',
        ])->getTargetUrl();

        $this->get($landing)->assertOk();
    }

    public function test_a_mentor_session_reaches_the_inbox_but_not_the_admin_pages(): void
    {
        $this->mentor();

        $this->post('/admin/login', [
            'email' => 'mentor@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('admin.support'));

        $this->get('/admin/support')->assertOk();
        $this->get('/admin/profile')->assertOk();

        $this->get('/admin/organizations')->assertStatus(403);
        $this->get('/admin/projects')->assertStatus(403);
        $this->getJson('/admin/api/organizations')->assertStatus(403);
    }

    public function test_a_suspended_admin_is_refused(): void
    {
        $this->admin(status: 'suspended');

        $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_pasted_email_with_surrounding_whitespace_is_accepted(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', [
            'email' => '  admin@test.com  ',
            'password' => 'password123',
        ])->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_capitalised_email_is_accepted(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', [
            'email' => 'Admin@Test.COM',
            'password' => 'password123',
        ])->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_organisation_admin_is_told_which_role_they_hold(): void
    {
        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => 'company@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'company_admin')->first()->id);

        $this->post('/admin/login', [
            'email' => 'company@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'company_admin',
            session('errors')->first('email')
        );
    }

    public function test_an_inactive_account_is_told_it_is_inactive(): void
    {
        $this->admin(status: 'suspended');

        $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'not active',
            session('errors')->first('email')
        );
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }

    public function test_an_admin_can_sign_out(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_list_endpoint_requires_a_session(): void
    {
        $this->getJson('/admin/api/organizations')->assertStatus(401);
    }

    public function test_the_list_endpoint_refuses_a_learner(): void
    {
        $this->actingAs($this->learner())
            ->getJson('/admin/api/organizations')
            ->assertStatus(403);
    }

    public function test_an_admin_session_can_list_organizations(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->organization('pending');
        $verified = $this->organization('verified');

        $response = $this->getJson('/admin/api/organizations')->assertOk();

        $this->assertSame(2, count($response->json('data.data')));

        $filtered = $this->getJson('/admin/api/organizations?status=pending')->assertOk();
        $ids = collect($filtered->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains($verified->id));
    }

    public function test_the_detail_endpoint_is_reachable_with_a_session(): void
    {
        $this->actingAs($this->admin());
        $org = $this->organization();
        $org->forceFill(['description' => 'A software company building developer tools.'])->save();

        $this->getJson("/admin/api/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Test Company')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.description', 'A software company building developer tools.');
    }

    public function test_the_proof_link_points_at_the_session_route(): void
    {
        $this->actingAs($this->admin());
        $org = $this->organization();

        UploadedFile::forceCreate([
            'fileable_type' => Organization::class,
            'fileable_id' => $org->id,
            'type' => 'certificate',
            'path' => 'proofs/proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'status' => 'pending',
        ]);

        $this->getJson("/admin/api/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.proof_file.download_url',
                route('admin.web.organizations.proof-file', $org->id)
            );
    }

    public function test_an_admin_can_approve_through_the_web_endpoint(): void
    {
        Storage::fake('local');
        Notification::fake();

        $admin = $this->admin();
        $org = $this->organization();

        Storage::disk('local')->put('proofs/proof.pdf', '%PDF-1.4 fake proof');
        UploadedFile::forceCreate([
            'fileable_type' => Organization::class,
            'fileable_id' => $org->id,
            'type' => 'certificate',
            'path' => 'proofs/proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/api/organizations/{$org->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->assertSame('verified', $org->fresh()->verification_status);
        $this->assertSame($admin->id, $org->fresh()->verified_by);
    }
}
