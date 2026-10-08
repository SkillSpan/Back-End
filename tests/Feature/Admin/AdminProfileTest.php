<?php

namespace Tests\Feature\Admin;

use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The panel profile — the one screen both panel audiences own.
 *
 * The interesting properties are not the happy path but the boundaries:
 *
 *   - it is reachable by a mentor as well as an administrator, and by nobody
 *     else;
 *   - it can only ever edit the caller's own row — there is no id in any route
 *     or payload, and several cases below try to prove that from the outside;
 *   - the name is shared identity and the rest is presentation, so one save
 *     writes to two tables without either half being able to clobber the other.
 *
 * The avatar is deliberately NOT tested here. It is a pipeline of its own —
 * decode, crop, re-encode, store, serve, remove — with almost nothing in common
 * with the form above, and it lives in {@see AdminAvatarTest}.
 *
 * The panel is session-authenticated, so `actingAs` is used rather than a
 * Sanctum token — matching AdminSupportPanelTest and AdminProjectPanelTest.
 */
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

    // ------------------------------------------------------------ page access

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
        // The whole reason this route is not behind the `admin` middleware: a
        // mentor must be able to change the password the seeder chose.
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

    // ----------------------------------------------------------- API access

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

        // A valid payload, so the refusal is the guard rather than validation.
        $this->actingAs($learner)
            ->patchJson('/admin/api/profile', ['name' => 'Renamed'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFILE_FORBIDDEN');

        $this->assertSame('Panel Learner', $learner->fresh()->name);
    }

    // ------------------------------------------------------------------ show

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
        // Mentor identity is a ProfessionalProfile attribute, not a role slug,
        // so a role-only check would label every mentor "Member".
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

        // Created on first access rather than backfilled by a migration, so the
        // table stays proportional to the people who actually use the screen.
        $this->assertDatabaseHas('admin_profiles', [
            'user_id' => $admin->id,
            'display_title' => null,
            'bio' => null,
            'age' => null,
        ]);
    }

    // ---------------------------------------------------------- updateDetails

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

        // `name` is shared identity; the rest is presentation. The split is an
        // implementation detail the client never sees.
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

        // The rules are `sometimes`, so an absent key means "leave it" — that
        // is what lets the UI save one field without wiping the rest.
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

        // NULL rather than empty text: the UI renders both as "not set", but a
        // NULL keeps "never filled in" distinguishable from "deliberately
        // emptied" for anything that later queries the column.
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

        // There is no id in any route or payload, so there is no request shape
        // that edits somebody else's row. This pins that from the outside.
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

        // The column is an unsigned tinyint and would happily accept 12 or 200;
        // the range is the only thing that makes the value mean "a person's age".
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

    // ------------------------------------------------------------- the sidebar

    public function test_every_panel_page_links_to_the_profile_and_the_inbox(): void
    {
        $admin = $this->administrator();

        // The panel has no shared layout — every page repeats its sidebar by
        // hand — so a page can ship with nothing pointing at it. That is exactly
        // what happened to the support inbox: it was live and unreachable from
        // the other screens. This pins the whole set rather than trusting each
        // edit, which is the only way that class of bug stays fixed.
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

        // The sidebar's job is to never offer a link that answers 403. For a
        // mentor that means the inbox and their own profile, and none of the
        // pages behind the `admin` middleware.
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

    // ----------------------------------------------------------- the password

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

        // The `password` cast hashes on assignment, so the plaintext never
        // reaches the column.
        $this->assertTrue(Hash::check('a-much-better-secret', (string) $admin->fresh()->password));
        $this->assertNotSame('a-much-better-secret', $admin->fresh()->password);
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

        // The old password must still work — a refused change changes nothing.
        $this->assertTrue(Hash::check('password123', (string) $admin->fresh()->password));
    }

    public function test_the_current_password_is_required_even_with_a_live_session(): void
    {
        // A session is also what an unattended browser holds, so requiring the
        // existing password turns a walk-up into a permanent takeover.
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

    // ---------------------------------------------------------- support stats

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

        // Showing a mentor platform-wide totals would contradict the inbox one
        // click away, and the numbers would be meaningless to them.
        $this->actingAs($mentor)
            ->getJson('/admin/api/profile')
            ->assertOk()
            ->assertJsonPath('data.stats.scope', 'assigned')
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.stats.resolved', 1);
    }

    // --------------------------------------------------------------- helpers

    private function administrator(string $name = 'Panel Admin'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->adminRole->id);

        return $user->fresh();
    }

    private function mentor(string $name = 'Panel Mentor'): User
    {
        $user = $this->user($name);

        // Mentor identity is a ProfessionalProfile attribute, not a role slug.
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
        // `example.com`, never `test.com`: test.com addresses are disposable
        // under the `indisposable` rule, so assertions about them can pass for
        // the wrong reason.
        return User::forceCreate([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
