<?php

namespace Tests\Unit;

use App\Models\AccountVerification;
use App\Models\AdminProfile;
use App\Models\AuthSession;
use App\Models\User;
use Tests\TestCase;

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
