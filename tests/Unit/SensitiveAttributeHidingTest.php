<?php

namespace Tests\Unit;

use App\Models\AccountVerification;
use App\Models\AdminProfile;
use App\Models\AuthSession;
use App\Models\User;
use Tests\TestCase;

/**
 * Every model that carries a secret must hide it from array/JSON output.
 *
 * This is cheap to state and expensive to lose: a single `return $model` in a
 * future controller is enough to leak a value that was never meant to leave the
 * server, and nothing else in the suite would notice.
 *
 * Two of these are worse than "embarrassing":
 *
 *   - `account_verifications.challenge_state` is a bcrypt hash of a SIX DIGIT
 *     OTP. Six digits is a million candidates, so a leaked hash is the OTP —
 *     the hash buys nothing against an offline attack.
 *   - `auth_sessions.token_hash` is the lookup key for a live session.
 *
 * The models are not serialised into any response today; these assertions are
 * the guard for the day one is.
 */
class SensitiveAttributeHidingTest extends TestCase
{
    public function test_the_user_model_hides_its_credentials(): void
    {
        $hidden = (new User)->getHidden();

        $this->assertContains('password', $hidden);
        $this->assertContains('remember_token', $hidden);
    }

    public function test_the_account_verification_model_hides_the_otp_hash(): void
    {
        $this->assertContains('challenge_state', (new AccountVerification)->getHidden());
    }

    public function test_the_auth_session_model_hides_the_token_hash(): void
    {
        $this->assertContains('token_hash', (new AuthSession)->getHidden());
    }

    public function test_the_admin_profile_model_hides_the_avatar_blob(): void
    {
        $this->assertContains('avatar_data', (new AdminProfile)->getHidden());
    }
}
