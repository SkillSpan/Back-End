<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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

    public function test_matches_regardless_of_case_and_surrounding_whitespace(): void
    {
        User::factory()->create(['email' => 'mixed@example.com']);

        $this->postJson(self::ENDPOINT, ['email' => '  MiXeD@ExAmPlE.CoM  '])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.email', 'mixed@example.com');
    }

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

    public function test_when_it_says_available_registration_succeeds(): void
    {
        $email = 'fresh@example.com';

        $this->postJson(self::ENDPOINT, ['email' => $email])
            ->assertJsonPath('data.available', true);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload($email))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

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

    public function test_returns_no_account_details_beyond_the_boolean(): void
    {
        $user = User::factory()->create([
            'name' => 'Secret Name',
            'email' => 'leak@example.com',
            'status' => 'suspended',
        ]);

        $response = $this->postJson(self::ENDPOINT, ['email' => 'leak@example.com'])
            ->assertStatus(200);

        $this->assertSame(
            ['email', 'exists', 'available'],
            array_keys($response->json('data'))
        );

        $response->assertJsonMissing(['id' => $user->id]);
        $response->assertJsonMissing(['name' => 'Secret Name']);
        $response->assertJsonMissing(['status' => 'suspended']);
        $this->assertStringNotContainsString('Secret Name', $response->getContent());
    }

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
