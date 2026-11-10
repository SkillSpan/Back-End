<?php

namespace Tests\Feature\Assistant;

use App\Models\AlgorithmConfiguration;
use App\Models\AssistantInteraction;
use App\Models\CareerRole;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Assistant\AssistantAnswer;
use App\Services\Assistant\AssistantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantAskTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        config([
            'services.assistant.enabled' => false,
            'services.assistant.approval_reference' => null,
        ]);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => ['skill_scale' => [0, 5]],
            'activated_at' => now(),
        ]);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])->assertStatus(401);
    }

    public function test_non_learner_cannot_use_the_assistant(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_learner_without_a_profile_is_rejected(): void
    {
        $user = $this->createUserWithRole($this->learnerRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }

    public function test_an_intent_outside_the_permitted_scope_is_refused(): void
    {
        [$user] = $this->createLearner();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'write_my_project_submission',
            'question' => 'Write my final project report for me.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSISTANT_INTENT_NOT_ALLOWED');
    }

    public function test_a_missing_intent_is_a_plain_validation_error(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', ['question' => 'Why?'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSISTANT_INTENT_NOT_ALLOWED');
    }

    public function test_an_out_of_scope_intent_never_reaches_the_service(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => 'GOV-123',
        ]);

        Http::fake();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'complete_my_assessment',
            'question' => 'Answer the assessment for me.',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_assistant_is_off_by_default(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_NOT_ENABLED');
    }

    public function test_enabling_without_a_recorded_approval_still_fails_closed(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        config(['services.assistant.enabled' => true]);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_APPROVAL_NOT_RECORDED');
    }

    public function test_a_blank_approval_reference_is_treated_as_missing(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => '   ',
        ]);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_APPROVAL_NOT_RECORDED');
    }

    public function test_a_closed_gate_sends_nothing_to_the_service(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        Http::fake();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_the_governance_gate_is_asserted_before_configuration_is_resolved(): void
    {
        AlgorithmConfiguration::query()->update(['status' => 'retired']);

        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_NOT_ENABLED');
    }

    public function test_it_refuses_when_no_active_configuration_exists(): void
    {
        AlgorithmConfiguration::query()->update(['status' => 'retired']);

        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_CONFIGURATION_INVALID');

        $this->assertSame(0, AssistantInteraction::query()->count());
    }

    public function test_with_the_gate_open_but_no_service_token_it_fails_locally(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        config(['services.assistant.service_token' => null]);

        Http::fake();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_NOT_CONFIGURED');

        Http::assertNothingSent();
    }

    public function test_a_failed_interaction_is_still_audited(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->configureTransport();

        Http::fake(['*' => Http::response(['detail' => 'boom'], 500)]);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])->assertStatus(503);

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame($profile->id, $interaction->student_profile_id);
        $this->assertSame('explain_readiness', $interaction->intent);
        $this->assertSame(AssistantInteraction::STATUS_FAILED, $interaction->response_status);
        $this->assertSame('ASSISTANT_UNAVAILABLE', $interaction->failure_code);
        $this->assertNotNull($interaction->configuration_version);
        $this->assertNotEmpty($interaction->request_id);
    }

    public function test_neither_the_question_nor_the_reply_is_ever_persisted(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->configureTransport();
        Http::fake(['*' => Http::response($this->assistantResponse(), 200)]);

        $question = 'My email is learner@example.com — why is my readiness low?';

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => $question,
        ])->assertStatus(201);

        $interaction = AssistantInteraction::query()->firstOrFail();
        $serialised = (string) json_encode($interaction->getAttributes());

        $this->assertStringNotContainsString('learner@example.com', $serialised);
        $this->assertStringNotContainsString($question, $serialised);
        $this->assertStringNotContainsString($this->assistantResponse()['reply'], $serialised);

        $this->assertStringStartsWith('ctx-', (string) $interaction->context_reference);
    }

    public function test_the_request_id_is_correlated_through_to_the_audit_row(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->configureTransport();
        Http::fake(['*' => Http::response($this->assistantResponse(), 200)]);

        $this->postJson(
            '/api/v1/assistant/ask',
            ['intent' => 'explain_roadmap', 'question' => 'What should I do next?'],
            ['X-Request-ID' => 'req-correlation-1'],
        )->assertStatus(201);

        $this->assertSame(
            'req-correlation-1',
            AssistantInteraction::query()->firstOrFail()->request_id,
        );
    }

    public function test_the_orchestrator_passes_the_learners_identity_question_and_snapshot(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $client = $this->fakeClientReturning($this->assistantResponse());

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])->assertStatus(201);

        $this->assertCount(1, $client->calls);

        $call = $client->calls[0];

        $this->assertSame((string) $profile->user_id, $call['user_id']);
        $this->assertSame('Which gap should I close first?', $call['message']);
        $this->assertNotEmpty($call['request_id']);

        $this->assertSame($profile->id, $call['context']['student_profile_id']);
    }

    public function test_a_permitted_intent_is_orchestrated_end_to_end(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse());

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.intent', 'explain_skill_gap')
            ->assertJsonPath('data.response_status', AssistantInteraction::STATUS_SUCCEEDED)
            ->assertJsonPath('data.prompt_version', 'v1');

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame($profile->id, $interaction->student_profile_id);
        $this->assertSame(AssistantInteraction::STATUS_SUCCEEDED, $interaction->response_status);
        $this->assertNull($interaction->failure_code);
    }

    public function test_the_reply_is_relayed_to_the_caller(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse());

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.reply', $this->assistantResponse()['reply'])
            ->assertJsonPath('data.provider_used', 'gemini');
    }

    public function test_a_total_provider_outage_is_recorded_as_failed_but_still_relays_the_fallback(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'reply' => "I'm temporarily unavailable. Please try again shortly.",
            'provider_used' => null,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.response_status', AssistantInteraction::STATUS_FAILED)
            ->assertJsonPath('data.failure_code', 'ASSISTANT_PROVIDERS_UNAVAILABLE')
            ->assertJsonPath('data.provider_used', null)
            ->assertJsonPath('data.reply', "I'm temporarily unavailable. Please try again shortly.");

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame(AssistantInteraction::STATUS_FAILED, $interaction->response_status);
        $this->assertSame('ASSISTANT_PROVIDERS_UNAVAILABLE', $interaction->failure_code);
    }

    public function test_the_service_token_is_never_exposed_to_the_client(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->configureTransport();
        $this->fakeClientReturning($this->assistantResponse());

        $response = $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])->assertStatus(201);

        $this->assertStringNotContainsString('test-service-token', (string) $response->getContent());
    }

    public function test_an_ungrounded_answer_offers_the_human_handoff(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'reply' => 'I do not have information about that in the documentation.',
            'answer_status' => AssistantAnswer::STATUS_INSUFFICIENT_CONTEXT,
            'grounded' => false,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'What is the refund policy for company-sponsored projects?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.answer_status', AssistantAnswer::STATUS_INSUFFICIENT_CONTEXT)
            ->assertJsonPath('data.grounded', false)
            ->assertJsonPath('data.handoff_available', true);

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame(AssistantAnswer::STATUS_INSUFFICIENT_CONTEXT, $interaction->answer_status);
        $this->assertFalse((bool) $interaction->grounded);
    }

    public function test_a_grounded_answer_does_not_offer_the_handoff(): void
    {
        [$user, $profile] = $this->createLearner();
        $this->giveSkillGapGrounding($profile);
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'answer_status' => AssistantAnswer::STATUS_ANSWERED,
            'grounded' => true,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.answer_status', AssistantAnswer::STATUS_ANSWERED)
            ->assertJsonPath('data.grounded', true)
            ->assertJsonPath('data.handoff_available', false);
    }

    public function test_a_question_whose_source_is_empty_offers_the_handoff(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'reply' => 'No skill-gap analysis is available for you yet.',
            'answer_status' => AssistantAnswer::STATUS_ANSWERED,
            'grounded' => true,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'What are my skill gaps?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.answer_status', AssistantAnswer::STATUS_ANSWERED)
            ->assertJsonPath('data.handoff_available', true);
    }

    public function test_the_offer_follows_the_intent_not_the_learners_data_richness(): void
    {
        [$user, $profile] = $this->createLearner();
        $this->giveReadinessGrounding($profile);
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'answer_status' => AssistantAnswer::STATUS_ANSWERED,
            'grounded' => true,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score 72?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.handoff_available', false);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'What are my skill gaps?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.handoff_available', true);
    }

    public function test_a_provider_outage_does_not_offer_the_handoff(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse([
            'reply' => "I'm temporarily unavailable. Please try again shortly.",
            'provider_used' => null,
            'answer_status' => AssistantAnswer::STATUS_INSUFFICIENT_CONTEXT,
        ]));

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.provider_used', null)
            ->assertJsonPath('data.handoff_available', false);
    }

    public function test_an_answer_without_the_status_field_does_not_offer_the_handoff(): void
    {
        [$user, $profile] = $this->createLearner();
        $this->giveSkillGapGrounding($profile);
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning($this->assistantResponse());

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.answer_status', null)
            ->assertJsonPath('data.handoff_available', false);
    }

    private function approveGate(): void
    {
        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => 'GOV-APPROVAL-TEST-1',
        ]);
    }

    private function configureTransport(): void
    {
        config([
            'services.assistant.url' => 'http://assistant.test',
            'services.assistant.path' => '/chat',
            'services.assistant.service_token' => 'test-service-token',
            'services.assistant.timeout' => 5,
        ]);
    }

    private function assistantResponse(array $overrides = []): array
    {
        return array_merge([
            'reply' => 'Your readiness score is weighted across three dimensions.',
            'provider_used' => 'gemini',
            'prompt_version' => 'v1',
            'timestamp' => '2026-09-22T10:00:00+00:00',
        ], $overrides);
    }

    private function fakeClientReturning(array $response): AssistantClient
    {
        $client = new class($response) extends AssistantClient
        {
            public array $calls = [];

            public function __construct(private readonly array $stub) {}

            public function ask(
                string $userId,
                string $message,
                array $contextSnapshot,
                string $requestId,
                string $intent,
            ): array {
                $this->calls[] = [
                    'user_id' => $userId,
                    'message' => $message,
                    'intent' => $intent,
                    'context' => $contextSnapshot,
                    'request_id' => $requestId,
                ];

                return $this->stub;
            }
        };

        $this->instance(AssistantClient::class, $client);

        return $client;
    }

    private function giveReadinessGrounding(StudentProfile $profile): void
    {
        ReadinessResult::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $profile->primary_career_role_id,
            'career_role_version' => 1,
            'score' => 72.5,
            'band' => 'near_ready',
            'critical_cap_applied' => false,
            'algorithm_version' => 'readiness-v1',
            'configuration_version' => 'config-v1',
            'calculated_at' => now(),
        ]);
    }

    private function giveSkillGapGrounding(StudentProfile $profile): void
    {
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);

        $snapshot = DecisionSnapshot::forceCreate([
            'decision_uuid' => (string) Str::uuid(),
            'student_profile_id' => $profile->id,
            'career_role_id' => $profile->primary_career_role_id,
            'career_role_version' => 1,
            'algorithm_version' => 'skill-gap-v1',
            'configuration_version' => 'config-v1',
            'request_id' => 'req-'.uniqid(),
            'snapshot' => [],
            'status' => 'succeeded',
            'calculated_at' => now(),
        ]);

        SkillGapResult::forceCreate([
            'decision_snapshot_id' => $snapshot->id,
            'skill_id' => $skill->id,
            'current_level' => 2.0,
            'required_level' => 4.0,
            'gap' => 2.0,
            'match_score' => 50.0,
            'importance_weight' => 0.45,
            'is_critical' => true,
            'status' => 'gap',
            'explanation' => 'Stored explanation.',
        ]);
    }

    private function createLearner(): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        return [$user, $profile];
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }
}
