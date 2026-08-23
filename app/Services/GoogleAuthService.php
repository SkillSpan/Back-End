<?php

namespace App\Services;

use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Google_Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GoogleAuthService
{
    /**
     * Authenticate a user using a Google ID token.
     *
     * @return array{user: User, token: string}
     */
    public function login(
        string $credential,
        bool $termsAccepted = false,
        bool $privacyAccepted = false
    ): array {
        $payload = $this->verifyGoogleToken($credential);

        $googleId = $payload['sub'] ?? null;
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = (bool) ($payload['email_verified'] ?? false);

        if (! $googleId || ! $email) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid Google account information.',
            ]);
        }

        if (! $emailVerified) {
            throw ValidationException::withMessages([
                'credential' => 'Your Google email address must be verified.',
            ]);
        }

        return DB::transaction(function () use (
            $googleId,
            $email,
            $payload,
            $termsAccepted,
            $privacyAccepted
        ) {
            $user = User::where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            if ($user) {
                return $this->loginExistingUser(
                    $user,
                    $googleId,
                    $email
                );
            }

            if (! $termsAccepted || ! $privacyAccepted) {
                throw ValidationException::withMessages([
                    'terms_accepted' => 'You must accept the Terms and Conditions.',
                    'privacy_accepted' => 'You must accept the Privacy Policy.',
                ]);
            }

            $user = $this->createGoogleUser(
                $googleId,
                $email,
                $payload
            );

            $this->assignLearnerRole($user);
            $this->createStudentProfile($user);

            $token = $user->createToken('auth_token')->plainTextToken;

            return [
                'user' => $user->fresh(['roles', 'studentProfile']),
                'token' => $token,
            ];
        });
    }

    private function verifyGoogleToken(string $credential): array
    {
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            Log::error('Google Client ID is not configured.');

            throw ValidationException::withMessages([
                'credential' => 'Google authentication is not configured.',
            ]);
        }

        $client = new Google_Client([
            'client_id' => $clientId,
        ]);

        $payload = $client->verifyIdToken($credential);

        if (! $payload) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid or expired Google credential.',
            ]);
        }

        return $payload;
    }

    private function loginExistingUser(
        User $user,
        string $googleId,
        string $email
    ): array {
        if ($user->email !== $email) {
            throw ValidationException::withMessages([
                'credential' => 'Google account information does not match the user account.',
            ]);
        }

        if ($user->status !== 'active' || ! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'credential' => 'Your account is not active. Please complete account verification first.',
            ]);
        }

        /*
         * If the account was originally created with email/password,
         * link it to the verified Google account.
         */
        if (! $user->google_id) {
            $user->google_id = $googleId;
        } elseif ($user->google_id !== $googleId) {
            throw ValidationException::withMessages([
                'credential' => 'This Google account is not linked to this user.',
            ]);
        }

        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user->fresh(['roles', 'studentProfile']),
            'token' => $token,
        ];
    }

    private function createGoogleUser(
        string $googleId,
        string $email,
        array $payload
    ): User {
        return User::create([
            'name' => $payload['name'] ?? Str::before($email, '@'),
            'email' => $email,
            'google_id' => $googleId,

            /*
             * Google users do not authenticate with this password.
             * The User model's "hashed" cast hashes it automatically.
             */
            'password' => Str::random(64),

            'locale' => 'en',
            'status' => 'active',
            'email_verified_at' => now(),
            'last_login_at' => now(),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
    }

    private function assignLearnerRole(User $user): void
    {
        $role = Role::firstOrCreate(
            ['slug' => 'learner'],
            [
                'name' => 'Learner',
                'description' => 'Auto-generated role',
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $role->id => ['organization_id' => null],
        ]);
    }

    private function createStudentProfile(User $user): void
    {
        StudentProfile::create([
            'user_id' => $user->id,
            'education' => null,
            'specialization' => null,
            'career_status' => null,
            'enrollment_status' => 'enrolled',
            'graduation_status' => false,
            'visibility' => 'private',
            'consent_given' => true,
            'completeness_percent' => 15,
        ]);
    }
}