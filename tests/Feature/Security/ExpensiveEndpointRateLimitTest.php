<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpensiveEndpointRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Storage::fake('local');

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function learner(string $email = 'learner@example.com'): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function learnerWithProfile(string $email = 'learner@example.com'): User
    {
        $user = $this->learner($email);
        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);
    }

    private function assertThrottled(callable $send, string $configKey, int $limit = 1): void
    {
        config([$configKey => $limit]);

        for ($spent = 0; $spent < $limit; $spent++) {
            $this->assertNotSame(
                429,
                $send()->getStatusCode(),
                'a request inside the budget was throttled',
            );
        }

        $send()->assertStatus(429);
    }

    public function test_assistant_ask_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/assistant/ask', [
                'intent' => 'explain_readiness',
                'question' => 'What should I focus on next?',
            ]),
            'rate_limits.assistant_ask',
        );
    }

    public function test_readiness_calculate_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/readiness/calculate', []),
            'rate_limits.readiness_calculate',
        );
    }

    public function test_intelligence_calculate_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/intelligence/calculate', []),
            'rate_limits.intelligence_calculate',
        );
    }

    public function test_skill_match_shares_the_readiness_limiter(): void
    {
        $learner = $this->learnerWithProfile();
        Sanctum::actingAs($learner);

        config(['rate_limits.readiness_calculate' => 1]);

        $this->assertNotSame(
            429,
            $this->postJson('/api/v1/readiness/calculate', [])->getStatusCode(),
        );

        $this->postJson('/api/v1/skill-match', [
            'student_profile_id' => $learner->studentProfile->id,
            'career_role_id' => 1,
            'career_role_version' => 1,
            'user_id' => $learner->id,
            'target_role' => 'Backend Engineer',
            'skills' => [[
                'skill_id' => 1,
                'skill_name' => 'SQL',
                'current_level' => 2,
                'required_level' => 4,
                'importance_weight' => 0.8,
                'is_critical' => true,
            ]],
        ])->assertStatus(429);
    }

    public function test_project_matching_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/projects/1/match', []),
            'rate_limits.project_matching',
        );
    }

    public function test_evidence_upload_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());
        $skill = $this->skill();

        $this->assertThrottled(
            fn () => $this->post('/api/v1/evidence', [
                'skill_id' => $skill->id,
                'evidence_file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json']),
            'rate_limits.evidence_upload',
        );
    }

    public function test_support_write_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learner());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/support/requests', [
                'reason' => 'learner_requested',
            ]),
            'rate_limits.support_write',
        );
    }

    public function test_the_budget_is_per_user_and_not_shared(): void
    {
        config(['rate_limits.assistant_ask' => 1]);

        $first = $this->learnerWithProfile('first@example.com');
        $second = $this->learnerWithProfile('second@example.com');

        $ask = fn () => $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'What should I focus on next?',
        ]);

        Sanctum::actingAs($first);
        $this->assertNotSame(429, $ask()->getStatusCode());
        $ask()->assertStatus(429);

        Sanctum::actingAs($second);
        $this->assertNotSame(429, $ask()->getStatusCode());
    }

    public function test_each_endpoint_has_its_own_bucket(): void
    {
        config([
            'rate_limits.assistant_ask' => 1,
            'rate_limits.readiness_calculate' => 1,
        ]);

        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertNotSame(
            429,
            $this->postJson('/api/v1/assistant/ask', [
                'intent' => 'explain_readiness',
                'question' => 'What should I focus on next?',
            ])->getStatusCode(),
        );

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'What should I focus on next?',
        ])->assertStatus(429);

        $this->assertNotSame(
            429,
            $this->postJson('/api/v1/readiness/calculate', [])->getStatusCode(),
        );
    }

    public function test_baseline_submit_is_rate_limited(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        $this->assertThrottled(
            fn () => $this->postJson('/api/v1/baseline-assessments/1/submit', [
                'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
            ]),
            'rate_limits.baseline_submit',
        );
    }

    public function test_ordinary_usage_stays_well_inside_the_default_limits(): void
    {
        Sanctum::actingAs($this->learnerWithProfile());

        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(
                429,
                $this->postJson('/api/v1/assistant/ask', [
                    'intent' => 'explain_readiness',
                    'question' => 'Question number '.$i,
                ])->getStatusCode(),
            );
        }
    }
}
