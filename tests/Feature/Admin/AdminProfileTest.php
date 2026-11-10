<?php

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
    }

    public function test_the_page_renders_for_an_administrator(): void
    {
        $this->actingAs($this->administrator())
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Profile', false)
            ->assertSee('Details', false)
            ->assertSee('Password', false);
    }

    public function test_the_page_renders_for_a_mentor(): void
    {
        $this->actingAs($this->mentor())
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Profile', false);
    }

    public function test_the_page_is_refused_to_an_unrelated_account(): void
    {
        $this->actingAs($this->learner())
            ->get('/admin/profile')
            ->assertStatus(403);
    }

    public function test_a_guest_cannot_reach_the_api(): void
    {
        $this->getJson('/admin/api/profile')->assertStatus(401);
    }

    public function test_an_unrelated_account_is_refused_by_the_api(): void
    {
        $this->actingAs($this->learner())
            ->getJson('/admin/api/profile')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFILE_FORBIDDEN');
    }

    public function test_an_unrelated_account_cannot_edit_through_the_api(): void
    {
        $learner = $this->learner();

        $this->actingAs($learner)
            ->patchJson('/admin/api/profile', ['name' => 'Renamed'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFILE_FORBIDDEN');

        $this->assertSame('Panel Learner', $learner->fresh()->name);
    }

    public function test_the_payload_carries_identity_role_and_account_dates(): void
    {
        $admin = $this->administrator();
        $admin->forceFill(['last_login_at' => now()->subDay()])->save();

        $this->actingAs($admin)
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.name', $admin->name)
            ->assertJsonPath('data.user.email', $admin->email)
            ->assertJsonPath('data.role', 'Administrator')
            ->assertJsonPath('data.profile.display_name', 'Administrator')
            ->assertJsonPath('data.profile.has_avatar', false)
            ->assertJsonPath('data.profile.avatar_url', null)
            ->assertJsonPath('data.profile.limits.bio', 600);
    }

    public function test_the_role_slugs_are_exposed_for_the_panel(): void
    {
        $this->actingAs($this->administrator())
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('data.role_slugs', ['admin']);
    }

    public function test_a_mentor_is_labelled_as_a_mentor_not_a_member(): void
    {
        $this->actingAs($this->mentor())
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('data.role', 'Mentor')
            ->assertJsonPath('data.profile.display_name', 'Mentor');
    }

    public function test_reading_the_profile_creates_an_empty_row(): void
    {
        $admin = $this->administrator();

        $this->assertDatabaseMissing('admin_profiles', ['user_id' => $admin->id]);

        $this->actingAs($admin)->getJson('/admin/api/profile')->assertOk();

        $this->assertDatabaseHas('admin_profiles', [
            'user_id' => $admin->id,
            'display_title' => null,
            'bio' => null,
            'age' => null,
        ]);
    }

    public function test_the_name_is_written_to_users_and_the_rest_to_the_profile(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', [
                'name' => 'Layla Haddad',
                'display_title' => 'Head of Operations',
                'bio' => 'Keeps the review queue moving.',
                'age' => 34,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Layla Haddad')
            ->assertJsonPath('data.profile.display_title', 'Head of Operations')
            ->assertJsonPath('data.profile.bio', 'Keeps the review queue moving.')
            ->assertJsonPath('data.profile.age', 34);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Layla Haddad']);
        $this->assertDatabaseHas('admin_profiles', [
            'user_id' => $admin->id,
            'display_title' => 'Head of Operations',
            'bio' => 'Keeps the review queue moving.',
            'age' => 34,
        ]);
    }

    public function test_the_display_name_falls_back_to_the_title_once_it_is_set(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['display_title' => 'Operations Lead'])
            ->assertOk()
            ->assertJsonPath('data.profile.display_name', 'Operations Lead');
    }

    public function test_a_partial_update_leaves_the_other_fields_alone(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->patchJson('/admin/api/profile', [
            'name' => 'Layla Haddad',
            'display_title' => 'Operations Lead',
            'bio' => 'Original bio.',
            'age' => 34,
        ])->assertOk();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['display_title' => 'Senior Operations Lead'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Layla Haddad')
            ->assertJsonPath('data.profile.bio', 'Original bio.')
            ->assertJsonPath('data.profile.age', 34)
            ->assertJsonPath('data.profile.display_title', 'Senior Operations Lead');
    }

    public function test_an_empty_string_clears_a_field_to_null(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->patchJson('/admin/api/profile', [
            'bio' => 'Something.',
            'display_title' => 'Something else.',
        ])->assertOk();

        $this->actingAs($admin)->patchJson('/admin/api/profile', [
            'bio' => '   ',
            'display_title' => null,
            'age' => null,
        ])->assertOk();

        $this->assertDatabaseHas('admin_profiles', [
            'user_id' => $admin->id,
            'bio' => null,
            'display_title' => null,
            'age' => null,
        ]);
    }

    public function test_the_edit_only_touches_the_caller(): void
    {
        $admin = $this->administrator();
        $other = $this->administrator();
        $other->forceFill(['name' => 'Untouched Admin'])->save();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['name' => 'Changed', 'bio' => 'Mine.'])
            ->assertOk();

        $this->assertSame('Untouched Admin', $other->fresh()->name);
        $this->assertDatabaseMissing('admin_profiles', ['user_id' => $other->id]);
    }

    public function test_a_name_that_is_too_short_is_refused(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['name' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Panel Admin', $admin->fresh()->name);
    }

    public function test_an_age_outside_the_band_is_refused(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['age' => 12])
            ->assertStatus(422)
            ->assertJsonValidationErrors('age');

        $this->actingAs($admin)
            ->patchJson('/admin/api/profile', ['age' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('age');
    }

    public function test_an_overlong_bio_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->patchJson('/admin/api/profile', ['bio' => str_repeat('x', 601)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bio');
    }

    public function test_every_panel_page_links_to_the_profile_and_the_inbox(): void
    {
        $admin = $this->administrator();

        foreach (['/admin/organizations', '/admin/projects', '/admin/support', '/admin/profile'] as $page) {
            $this->actingAs($admin)
                ->get($page)
                ->assertOk()
                ->assertSee('href="'.route('admin.profile').'"', false)
                ->assertSee('href="'.route('admin.support').'"', false);
        }
    }

    public function test_a_mentors_sidebar_offers_only_the_pages_they_can_open(): void
    {
        $mentor = $this->mentor();

        foreach (['/admin/support', '/admin/profile'] as $page) {
            $this->actingAs($mentor)
                ->get($page)
                ->assertOk()
                ->assertSee('href="'.route('admin.profile').'"', false)
                ->assertSee('href="'.route('admin.support').'"', false)
                ->assertDontSee('href="'.route('admin.organizations').'"', false)
                ->assertDontSee('href="'.route('admin.projects').'"', false);
        }
    }

    public function test_the_password_can_be_changed_with_the_current_one(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'password123',
                'password' => 'a-much-better-secret',
                'password_confirmation' => 'a-much-better-secret',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('a-much-better-secret', (string) $admin->fresh()->password));
        $this->assertNotSame('a-much-better-secret', $admin->fresh()->password);
    }

    public function test_changing_the_password_revokes_every_api_token_and_session(): void
    {
        $admin = $this->administrator();

        foreach ([$admin->createToken('auth_token'), $admin->createToken('auth_token')] as $token) {
            AuthSession::forceCreate([
                'user_id' => $admin->id,
                'token_hash' => $token->accessToken->token,
                'result' => 'success',
                'issued_at' => now(),
            ]);
        }

        $this->assertSame(2, $admin->tokens()->count());
        $this->assertSame(2, AuthSession::where('user_id', $admin->id)->whereNull('revoked_at')->count());

        $this->actingAs($admin)
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'password123',
                'password' => 'a-much-better-secret',
                'password_confirmation' => 'a-much-better-secret',
            ])
            ->assertOk();

        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(0, AuthSession::where('user_id', $admin->id)->whereNull('revoked_at')->count());

        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_a_wrong_current_password_is_refused_in_the_project_envelope(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'not-my-password',
                'password' => 'a-much-better-secret',
                'password_confirmation' => 'a-much-better-secret',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_CURRENT_PASSWORD_INCORRECT');

        $this->assertTrue(Hash::check('password123', (string) $admin->fresh()->password));
    }

    public function test_the_current_password_is_required_even_with_a_live_session(): void
    {
        $this->actingAs($this->administrator())
            ->postJson('/admin/api/profile/password', [
                'password' => 'a-much-better-secret',
                'password_confirmation' => 'a-much-better-secret',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_the_new_password_must_be_long_enough_and_confirmed(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'password123',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($admin)
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'password123',
                'password' => 'a-much-better-secret',
                'password_confirmation' => 'a-different-secret',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $this->actingAs($this->administrator())
            ->postJson('/admin/api/profile/password', [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_an_administrators_stats_describe_the_whole_queue(): void
    {
        $admin = $this->administrator();
        $mentor = $this->mentor();

        $this->supportRequest($this->learner(), assignedTo: $mentor);
        $this->supportRequest($this->learner());
        $this->supportRequest($this->learner());

        $this->actingAs($admin)
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('data.stats.scope', 'all')
            ->assertJsonPath('data.stats.total', 3)
            ->assertJsonPath('data.stats.pending', 2)
            ->assertJsonPath('data.stats.assigned', 1);
    }

    public function test_a_mentors_stats_are_scoped_to_their_own_assignments(): void
    {
        $mentor = $this->mentor();
        $otherMentor = $this->mentor();

        $mine = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $this->supportRequest($this->learner(), assignedTo: $otherMentor);
        $this->supportRequest($this->learner());

        $mine->forceFill(['status' => SupportRequest::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($mentor)
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('data.stats.scope', 'assigned')
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.stats.resolved', 1);
    }

    private function administrator(string $name = 'Panel Admin'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->adminRole->id);

        return $user->fresh();
    }

    private function mentor(string $name = 'Panel Mentor'): User
    {
        $user = $this->user($name);

        ProfessionalProfile::create([
            'user_id' => $user->id,
            'type' => 'mentor',
            'expertise' => 'Backend engineering',
        ]);

        return $user->fresh();
    }

    private function learner(string $name = 'Panel Learner'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->learnerRole->id);

        return $user->fresh();
    }

    private function supportRequest(User $learner, ?User $assignedTo = null): SupportRequest
    {
        return SupportRequest::create([
            'user_id' => $learner->id,
            'assigned_to' => $assignedTo?->id,
            'status' => $assignedTo !== null
                ? SupportRequest::STATUS_ASSIGNED
                : SupportRequest::STATUS_PENDING,
            'reason' => SupportRequest::REASON_INSUFFICIENT_CONTEXT,
            'subject' => 'The assistant could not answer',
            'transcript' => [
                ['role' => 'learner', 'body' => 'How do I publish my project?'],
                ['role' => 'assistant', 'body' => 'I could not find that.'],
            ],
            'assigned_at' => $assignedTo !== null ? now() : null,
            'last_message_at' => now(),
        ]);
    }

    private function user(string $name): User
    {
        return User::forceCreate([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
