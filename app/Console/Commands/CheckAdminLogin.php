<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Answers one question: can this account sign in to the admin panel, and if
 * not, which check turns it away?
 *
 * The panel returns a deliberately vague message for a failed sign-in (so it
 * never reveals whether an address is registered), and Auth::attempt() cannot
 * distinguish "no such account" from "wrong password". That combination makes
 * a report like "the API works but the panel says the credentials do not
 * match" impossible to diagnose from the outside. This walks the same checks
 * the controller performs and names the one that fails.
 */
class CheckAdminLogin extends Command
{
    protected $signature = 'admin:check-login {email? : The account to check}';

    protected $description = 'Diagnose whether an account can sign in to the admin panel';

    public function handle(): int
    {
        $email = mb_strtolower(trim(
            $this->argument('email') ?: (string) $this->ask('Email')
        ));

        // withTrashed(): a soft-deleted account cannot sign in, and reporting
        // "no account exists" for it would send someone hunting for a typo.
        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $this->newLine();
            $this->error("No account exists for {$email}.");
            $this->line('The panel answers these with "These credentials do not match our records."');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');

        $roles = $user->roles()->pluck('slug');
        $passwordMatches = Hash::check($password, $user->password);

        $this->newLine();
        $this->line("  Account   #{$user->id}  {$user->email}");
        $this->line('  Status    '.$user->status.($user->trashed() ? '  <error>(SOFT DELETED)</error>' : ''));
        $this->line('  Verified  '.($user->email_verified_at ? 'yes' : '<error>no</error>'));
        $this->line('  Roles     '.($roles->implode(', ') ?: '<error>(none)</error>'));
        $this->line('  Password  '.($passwordMatches ? '<info>correct</info>' : '<error>does NOT match</error>'));
        $this->newLine();

        $blockers = array_values(array_filter([
            ! $passwordMatches ? 'the password does not match this account' : null,
            $user->trashed() ? 'the account is soft-deleted' : null,
            $user->status !== 'active' ? "the status is '{$user->status}', not 'active'" : null,
            ! $user->hasRole('admin') ? 'the account does not hold the platform "admin" role' : null,
        ]));

        if ($blockers !== []) {
            $this->error('The panel will refuse this account because:');
            foreach ($blockers as $blocker) {
                $this->line('  • '.$blocker);
            }
            $this->newLine();
            $this->line('The API may still accept it: /api/v1/auth/login only checks the password,');
            $this->line('the account status and the organisation approval — never the admin role.');

            return self::FAILURE;
        }

        $this->info('This account can sign in to the admin panel.');

        return self::SUCCESS;
    }
}
