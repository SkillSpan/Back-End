<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/v1/setup/create-mentor — the shared-secret mentor bootstrap.
 *
 * This endpoint registers the mentor outright: there is no OTP / verify
 * round-trip, the secret itself is the authorisation. Contract under test:
 * disabled without a configured secret, timing-safe secret comparison,
 * account creation for an unknown email, promotion of an existing active
 * account, activation of a stranded pending account, refusal of
 * suspended/deleted accounts (including soft-deleted, which must 422 rather
 * than blow up on the unique index), verified ProfessionalProfile upsert on
 * the unique user_id, input validation and the route-level throttle.
 */
class SetupCreateMentorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'mentor-setup-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mentor_setup.secret' => self::SECRET]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'secret' => self::SECRET,
            'email' => 'mentor@test.com',
            'expertise' => 'Backend Development',
            'affiliation' => 'SkillSpan',
            'availability' => 'weekdays',
        ], $overrides);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::forceCreate(array_merge([
            'name' => 'Existing User',
            'email' => 'mentor@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_is_disabled_when_no_secret_is_configured(): void
    {
        config(['services.mentor_setup.secret' => '']);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'disabled'));

        $this->assertSame(0, User::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_rejects_a_wrong_secret_without_creating_anything(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['secret' => 'wrong-guess']))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid setup secret.');

        $this->assertSame(0, User::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_registers_a_brand_new_mentor_without_any_otp_step(): void
    {
        $response = $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'name' => 'Fresh Mentor',
            'password' => 'mentor-password-1',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.type', 'mentor')
            // Regression guard: verification_status is NOT mass-assignable on
            // ProfessionalProfile, so a fill()-only write would leave the
            // column at its 'pending' default and this assertion would fail.
            ->assertJsonPath('data.verification_status', 'verified');

        $user = User::where('email', 'mentor@test.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Fresh Mentor', $user->name);
        $this->assertSame('active', $user->status);
        // No OTP round-trip, so the address is trusted up front.
        $this->assertNotNull($user->email_verified_at);

        $this->assertSame(1, User::count());
        $this->assertSame(1, ProfessionalProfile::count());

        // A caller-supplied password must never be echoed back.
        $this->assertNull($response->json('data.generated_password'));
    }

    public function test_generates_a_password_when_none_is_supplied(): void
    {
        $response = $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.registered', true);

        $generated = $response->json('data.generated_password');
        $this->assertIsString($generated);
        $this->assertSame(16, strlen($generated));

        // The minted password is real: it must authenticate the new mentor.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mentor@test.com',
            'password' => $generated,
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_newly_registered_mentor_can_reach_the_mentor_endpoints(): void
    {
        $response = $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'mentor-password-1',
        ]))->assertStatus(201);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'mentor@test.com',
            'password' => 'mentor-password-1',
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($token);

        // EnsureUserIsMentor gates on type=mentor AND verification_status=verified,
        // so a 200 here proves the privileged column really persisted.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/mentor/students')
            ->assertOk();

        $this->assertSame($response->json('data.user_id'), User::where('email', 'mentor@test.com')->first()->id);
    }

    public function test_promotes_an_existing_active_user_without_duplicating_the_account(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.type', 'mentor')
            ->assertJsonPath('data.verification_status', 'verified');

        $profile = ProfessionalProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('verified', $profile->verification_status);
        $this->assertSame('Backend Development', $profile->expertise);
        $this->assertSame('SkillSpan', $profile->affiliation);
        $this->assertSame('weekdays', $profile->availability);

        // Promotion must NOT mint a second account for the same person.
        $this->assertSame(1, User::count());
    }

    public function test_activates_a_pending_self_registered_user(): void
    {
        // Registered through /auth/register but never completed the OTP step.
        $user = $this->makeUser([
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.verification_status', 'verified');

        $fresh = $user->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->email_verified_at);

        $this->assertSame(1, User::count());
    }

    public function test_supplied_password_resets_an_existing_mentors_password(): void
    {
        // First call registers the account with a known password.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'original-password-1',
        ]))->assertStatus(201)->assertJsonPath('data.registered', true);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mentor@test.com',
            'password' => 'original-password-1',
        ])->assertOk();

        // The admin's recovery path for a forgotten password: call it again
        // with a new one. No OTP, no access to the mentor's inbox required.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'brand-new-password-2',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.password_reset', true)
            ->assertJsonPath('data.verification_status', 'verified');

        // Still a single account, and the new password is the live one.
        $this->assertSame(1, User::count());

        $user = User::where('email', 'mentor@test.com')->first();
        $this->assertTrue(Hash::check('brand-new-password-2', $user->password));
        $this->assertFalse(Hash::check('original-password-1', $user->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mentor@test.com',
            'password' => 'brand-new-password-2',
        ])->assertOk();
    }

    public function test_omitting_the_password_leaves_the_existing_one_untouched(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'original-password-1',
        ]))->assertStatus(201);

        // No password in the body means "do not touch it" — it must NOT mint a
        // fresh random one behind the mentor's back.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.password_reset', false)
            ->assertJsonPath('data.generated_password', null);

        $user = User::where('email', 'mentor@test.com')->first();
        $this->assertTrue(Hash::check('original-password-1', $user->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mentor@test.com',
            'password' => 'original-password-1',
        ])->assertOk();
    }

    public function test_promotes_an_existing_profile_in_place_instead_of_duplicating_it(): void
    {
        $user = $this->makeUser();

        // A pending reviewer profile already exists for this user.
        $existing = ProfessionalProfile::forceCreate([
            'user_id' => $user->id,
            'type' => 'reviewer',
            'verification_status' => 'pending',
        ]);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.professional_profile_id', $existing->id);

        // user_id is unique — the same row was updated, not a second one added.
        $this->assertSame(1, ProfessionalProfile::count());

        $fresh = $existing->fresh();
        $this->assertSame('mentor', $fresh->type);
        $this->assertSame('verified', $fresh->verification_status);
    }

    public function test_optional_fields_may_be_omitted(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', [
            'secret' => self::SECRET,
            'email' => 'bare@test.com',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'mentor')
            ->assertJsonPath('data.verification_status', 'verified')
            // name falls back to the local part of the address.
            ->assertJsonPath('data.name', 'bare');

        $profile = ProfessionalProfile::whereHas('user', fn ($q) => $q->where('email', 'bare@test.com'))->first();
        $this->assertNull($profile->expertise);
        $this->assertNull($profile->affiliation);
        $this->assertNull($profile->availability);
    }

    public function test_rejects_a_suspended_account(): void
    {
        $user = $this->makeUser(['status' => 'suspended']);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'suspended'));

        $this->assertSame(0, ProfessionalProfile::count());
        $this->assertSame('suspended', $user->fresh()->status);
    }

    public function test_rejects_a_soft_deleted_account_instead_of_crashing(): void
    {
        // A soft-deleted row still occupies the unique index on users.email.
        // If the lookup missed it, forceCreate() would raise a UNIQUE
        // constraint violation and the endpoint would return a 500.
        $user = $this->makeUser();
        $user->delete();

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'deleted'));

        $this->assertSame(0, ProfessionalProfile::count());
        // No second account was minted for the same address.
        $this->assertSame(1, User::withTrashed()->where('email', 'mentor@test.com')->count());
    }

    public function test_validates_mentor_input(): void
    {
        // Bad email format.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Missing email.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['email' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Supplied password shorter than 8 chars.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['password' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // expertise over the 2000 char cap.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['expertise' => str_repeat('a', 2001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expertise']);

        // availability over the 100 char cap.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload(['availability' => str_repeat('a', 101)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['availability']);

        $this->assertSame(0, User::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_route_throttle_blocks_secret_brute_forcing(): void
    {
        // throttle:10,1 — ten requests per minute per IP. Every attempt,
        // even wrong-secret 403s, counts toward the ceiling.
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/setup/create-mentor', $this->payload(['secret' => "guess-{$i}"]))
                ->assertStatus(403);
        }

        // The 11th attempt is rate-limited before it even reaches the
        // secret comparison.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(429);

        $this->assertSame(0, User::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    // =============================================== the kill switch

    public function test_is_disabled_by_the_kill_switch_even_with_the_correct_secret(): void
    {
        config(['services.mentor_setup.enabled' => false]);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'disabled'));

        $this->assertSame(0, User::count());
    }

    // ====================================== privilege escalation boundary

    public function test_the_mentor_secret_cannot_take_over_an_administrator(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $admin = $this->makeUser(['password' => 'admin-original-password']);
        $admin->roles()->attach($adminRole->id);

        /*
         * Before the guard this call reset the administrator's password — the
         * documented "recovery path" was a privilege-escalation path, because a
         * secret scoped to mentors could take over the highest-privileged
         * account on the platform.
         */
        $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'attacker-chosen-password',
        ]))->assertStatus(422)->assertJsonPath('success', false);

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('admin-original-password', $fresh->password));
        $this->assertFalse(Hash::check('attacker-chosen-password', $fresh->password));

        // …and the account was not otherwise touched: still an admin, and no
        // verified mentor profile was attached to it.
        $this->assertTrue($fresh->hasRole('admin'));
        $this->assertSame('active', $fresh->status);
        $this->assertNull(ProfessionalProfile::where('user_id', $admin->id)->first());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_an_administrator_is_refused_even_without_a_password(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $admin = $this->makeUser();
        $admin->roles()->attach($adminRole->id);

        // No password supplied, so nothing would be overwritten — the refusal
        // is about the boundary, not about credential replacement.
        $this->postJson('/api/v1/setup/create-mentor', $this->payload())
            ->assertStatus(422);

        $this->assertNull(ProfessionalProfile::where('user_id', $admin->id)->first());
    }

    // =============================================== audit trail

    public function test_a_successful_provisioning_is_audited(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', $this->payload())->assertStatus(201);

        $user = User::where('email', 'mentor@test.com')->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => null,
            'action' => 'setup.mentor_provisioned',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'purpose' => 'shared_secret_bootstrap',
        ]);

        $event = AuditEvent::where('action', 'setup.mentor_provisioned')->firstOrFail();
        $this->assertTrue($event->after['registered']);
        $this->assertFalse($event->after['password_reset']);
    }

    public function test_a_password_reset_through_the_mentor_secret_is_audited(): void
    {
        $this->postJson('/api/v1/setup/create-mentor', $this->payload())->assertStatus(201);

        $this->postJson('/api/v1/setup/create-mentor', $this->payload([
            'password' => 'brand-new-password-2',
        ]))->assertStatus(201);

        $user = User::where('email', 'mentor@test.com')->firstOrFail();

        // The flag answers "did the mentor secret ever touch this account's
        // credentials", which is the question an incident review actually asks.
        $event = AuditEvent::where('action', 'setup.mentor_provisioned')
            ->where('entity_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($event->after['password_reset']);
    }
}
