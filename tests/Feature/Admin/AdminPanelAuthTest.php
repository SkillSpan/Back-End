<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Role;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Session sign-in for the browser-based admin review panel.
 *
 * The panel used to authenticate by pasting a Sanctum Bearer token into a
 * text box, which is why the page answered "ما قدرنا نجيب الطلبات:
 * Unauthenticated." whenever no valid token was present. These tests pin the
 * replacement: a real email + password login, a session the panel's
 * JavaScript can rely on, and the authorization boundary around both.
 */
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

    /*
    |--------------------------------------------------------------------------
    | The panel is protected
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin/organizations')->assertRedirect(route('login'));
    }

    public function test_the_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('تسجيل الدخول');
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

    /**
     * Suspension has to end an already-open session, not merely block the
     * next login: the panel is session-based now, so a session minted before
     * the suspension would otherwise keep working until it expired.
     */
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

    /**
     * API clients must keep receiving the machine-readable 403 rather than a
     * redirect — the web-aware branch must not leak into the API.
     */
    public function test_the_api_still_gets_a_json_403_when_suspended(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($admin);

        $this->getJson('/admin/api/organizations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    /*
    |--------------------------------------------------------------------------
    | Signing in
    |--------------------------------------------------------------------------
    */

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

    /**
     * Knowing a valid password is not enough — the panel is admin-only, so a
     * learner signing in with their own correct credentials must be refused
     * rather than handed a session.
     */
    public function test_a_learner_with_valid_credentials_is_refused(): void
    {
        $this->learner();

        $this->post('/admin/login', [
            'email' => 'learner@test.com',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
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

    /**
     * Regression: the API login normalises the address (strtolower + trim)
     * before looking the account up, while Auth::attempt() uses whatever the
     * browser sent. The two must not disagree on identical input, or the same
     * credentials "work on the API but not in the panel".
     */
    public function test_a_pasted_email_with_surrounding_whitespace_is_accepted(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', [
            'email' => '  admin@test.com  ',
            'password' => 'password123',
        ])->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
    }

    /**
     * Case matters here because the comparison is not case-insensitive on
     * every driver: MySQL's utf8mb4_unicode_ci matches regardless, but SQLite
     * (and therefore this test suite) does not. Normalising keeps the panel
     * behaving the same on both.
     */
    public function test_a_capitalised_email_is_accepted(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', [
            'email' => 'Admin@Test.COM',
            'password' => 'password123',
        ])->assertRedirect(route('admin.organizations'));

        $this->assertAuthenticatedAs($admin);
    }

    /**
     * "Works on the API but not in the panel" is usually this: an organisation
     * admin signs in fine through /api/v1/auth/login/organization, then the
     * panel refuses them. The message has to name the role they actually hold
     * so it does not read like a wrong password.
     */
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

        // Even the correct password is refused once the lockout is in place.
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

    /*
    |--------------------------------------------------------------------------
    | The panel's data endpoints
    |--------------------------------------------------------------------------
    */

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

    /**
     * This is the request the panel makes on load — the one that used to fail
     * with "ما قدرنا نجيب الطلبات: Unauthenticated.".
     */
    public function test_an_admin_session_can_list_organizations(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->organization('pending');
        $verified = $this->organization('verified');

        $response = $this->getJson('/admin/api/organizations')->assertOk();

        $this->assertSame(2, count($response->json('data.data')));

        // The status tabs the panel renders rely on this filter.
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

        // This is the exact payload the panel's expanded card renders, so the
        // description has to survive the session-authenticated path too.
        $this->getJson("/admin/api/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Test Company')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.description', 'A software company building developer tools.');
    }

    /**
     * The browser follows the proof link with a session cookie, not a token,
     * so it has to point at the web route — the API route would 401 it.
     */
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

    /**
     * The inherited review workflow has to keep working over the session —
     * the panel's approve/reject buttons depend on it.
     */
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
