<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/auth/login/google — input-contract and configuration
 * failure paths. The happy path requires Google's SDK to verify a real
 * ID token against Google's public keys (network), so it is exercised in
 * staging instead; here we pin the two behaviors that must never change:
 * the credential is mandatory, and a missing GOOGLE_CLIENT_ID fails with
 * an explicit, non-fatal validation error rather than a 500.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    public function test_credential_is_required(): void
    {
        $this->postJson('/api/v1/auth/login/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
    }

    public function test_terms_acceptance_is_validated_when_sent(): void
    {
        // terms_accepted present but falsy → rejected by the FormRequest.
        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'any-token-shape',
            'terms_accepted' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);
    }

    public function test_fails_cleanly_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        // accepted is an IMPLICIT rule — it fires even when the key is
        // absent, so the contract requires explicit consent up front.
        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'some-id-token',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['credential'])
            // The explicit config error message, not a generic crash.
            ->assertJsonPath('errors.credential.0', fn ($message) => str_contains($message, 'not configured'));
    }
}
