<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /api/v1/auth/check-email
 *
 * The endpoint answers one question — "is this address already registered?" —
 * and nothing else. The tests below are grouped around the two things that can
 * actually go wrong with such an endpoint:
 *
 *   1. It lies. Either by mismatching registration's normalisation, or by
 *      ignoring soft-deleted rows (which still occupy the unique index). Both
 *      are covered here, and the last two tests prove the agreement end to end
 *      by actually calling POST /auth/register and checking that the verdict
 *      held.
 *   2. It leaks. It must return a boolean, never any attribute of the account.
 *
 * Fixtures use example.com. test.com is on the disposable-domains blocklist and
 * would make the "available" tests pass for the wrong reason.
 */
class CheckEmailTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/check-email';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    private function registrationPayload(string $email): array
    {
        return [
            'user_type' => 'individual',
            'name' => 'Test Learner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }

    // ─── the basic answer ───────────────────────────────────────────────────

    public function test_reports_an_unregistered_email_as_available(): void
    {
        $this->postJson(self::ENDPOINT, ['email' => 'nobody@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.available', true);
    }

    public function test_reports_a_registered_email_as_taken(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(self::ENDPOINT, ['email' => 'taken@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.available', false);
    }

    // ─── it must not lie ────────────────────────────────────────────────────

    public function test_matches_regardless_of_case_and_surrounding_whitespace(): void
    {
        User::factory()->create(['email' => 'mixed@example.com']);

        $this->postJson(self::ENDPOINT, ['email' => '  MiXeD@ExAmPlE.CoM  '])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true)
            // the echoed address is the normalised one, so the client can
            // trust what it displays back
            ->assertJsonPath('data.email', 'mixed@example.com');
    }

    /**
     * A soft-deleted account still owns the unique index on users.email, so
     * `unique:users,email` still rejects the address at registration. The
     * endpoint has to agree, or it tells the user "available" and registration
     * then fails — the frontend/backend disagreement this endpoint exists to
     * prevent.
     */
    public function test_counts_a_soft_deleted_account_as_registered(): void
    {
        $user = User::factory()->create(['email' => 'deleted@example.com']);
        $user->delete();

        $this->assertSoftDeleted('users', ['email' => 'deleted@example.com']);

        $this->postJson(self::ENDPOINT, ['email' => 'deleted@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.available', false);
    }

    /**
     * The end-to-end proof, half one: when the endpoint says available,
     * registration must actually succeed.
     */
    public function test_when_it_says_available_registration_succeeds(): void
    {
        $email = 'fresh@example.com';

        $this->postJson(self::ENDPOINT, ['email' => $email])
            ->assertJsonPath('data.available', true);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload($email))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    /**
     * The end-to-end proof, half two: when the endpoint says taken,
     * registration must actually refuse the address.
     */
    public function test_when_it_says_taken_registration_refuses_the_address(): void
    {
        $email = 'already@example.com';
        User::factory()->create(['email' => $email]);

        $this->postJson(self::ENDPOINT, ['email' => $email])
            ->assertJsonPath('data.exists', true);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload($email))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ─── it must not leak ───────────────────────────────────────────────────

    public function test_returns_no_account_details_beyond_the_boolean(): void
    {
        $user = User::factory()->create([
            'name' => 'Secret Name',
            'email' => 'leak@example.com',
            'status' => 'suspended',
        ]);

        $response = $this->postJson(self::ENDPOINT, ['email' => 'leak@example.com'])
            ->assertStatus(200);

        // The response body must contain exactly email / exists / available.
        $this->assertSame(
            ['email', 'exists', 'available'],
            array_keys($response->json('data'))
        );

        $response->assertJsonMissing(['id' => $user->id]);
        $response->assertJsonMissing(['name' => 'Secret Name']);
        $response->assertJsonMissing(['status' => 'suspended']);
        $this->assertStringNotContainsString('Secret Name', $response->getContent());
    }

    // ─── validation ─────────────────────────────────────────────────────────

    public function test_requires_an_email(): void
    {
        $this->postJson(self::ENDPOINT, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_a_malformed_email(): void
    {
        $this->postJson(self::ENDPOINT, ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_an_email_longer_than_255_characters(): void
    {
        $this->postJson(self::ENDPOINT, ['email' => str_repeat('a', 250).'@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ─── abuse mitigation ───────────────────────────────────────────────────

    /**
     * The endpoint is an enumeration surface, so the throttle is part of the
     * contract rather than a nicety: it must refuse a fast sweep.
     */
    public function test_is_throttled_after_ten_requests(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::ENDPOINT, ['email' => "sweep{$i}@example.com"])
                ->assertStatus(200);
        }

        $this->postJson(self::ENDPOINT, ['email' => 'sweep11@example.com'])
            ->assertStatus(429);
    }
}
