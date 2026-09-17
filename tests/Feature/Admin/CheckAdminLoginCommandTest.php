<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel answers a failed sign-in vaguely on purpose, and Auth::attempt()
 * cannot tell "no such account" from "wrong password" — so this command is the
 * only way to find out which check turned an operator away.
 */
class CheckAdminLoginCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function user(string $role = 'admin', string $status = 'active'): User
    {
        $user = User::forceCreate([
            'name' => 'Someone',
            'email' => 'someone@test.com',
            'password' => 'password123',
            'status' => $status,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user;
    }

    public function test_it_reports_an_unknown_account(): void
    {
        $this->artisan('admin:check-login', ['email' => 'nobody@test.com'])
            ->expectsOutputToContain('No account exists for nobody@test.com.')
            ->assertExitCode(1);
    }

    public function test_it_confirms_an_admin_with_the_right_password(): void
    {
        $this->user();

        $this->artisan('admin:check-login', ['email' => 'someone@test.com'])
            ->expectsQuestion('Password', 'password123')
            ->expectsOutputToContain('This account can sign in to the admin panel.')
            ->assertExitCode(0);
    }

    public function test_it_names_a_wrong_password(): void
    {
        $this->user();

        $this->artisan('admin:check-login', ['email' => 'someone@test.com'])
            ->expectsQuestion('Password', 'not-the-password')
            ->expectsOutputToContain('the password does not match this account')
            ->assertExitCode(1);
    }

    /**
     * The case behind "it works on the API but not in the panel": the API
     * login never checks the admin role, so an organisation admin signs in
     * there happily and is refused here.
     */
    public function test_it_names_a_missing_admin_role(): void
    {
        $this->user('company_admin');

        $this->artisan('admin:check-login', ['email' => 'someone@test.com'])
            ->expectsQuestion('Password', 'password123')
            ->expectsOutputToContain('does not hold the platform "admin" role')
            ->assertExitCode(1);
    }

    public function test_it_names_an_inactive_account(): void
    {
        $this->user('admin', 'suspended');

        $this->artisan('admin:check-login', ['email' => 'someone@test.com'])
            ->expectsQuestion('Password', 'password123')
            ->expectsOutputToContain("the status is 'suspended', not 'active'")
            ->assertExitCode(1);
    }

    public function test_it_normalises_the_email(): void
    {
        $this->user();

        $this->artisan('admin:check-login', ['email' => '  Someone@Test.COM  '])
            ->expectsQuestion('Password', 'password123')
            ->expectsOutputToContain('This account can sign in to the admin panel.')
            ->assertExitCode(0);
    }
}
