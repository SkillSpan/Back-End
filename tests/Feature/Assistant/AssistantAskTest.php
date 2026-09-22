<?php

namespace Tests\Feature\Assistant;

use App\Models\AlgorithmConfiguration;
use App\Models\AssistantInteraction;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Assistant\AssistantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-REC-01 — POST /api/v1/assistant/ask
 *
 * Covers the gateway guarantees that hold independently of the FastAPI
 * contract: authorization, the §12.5 governance gate, the assistant scope
 * whitelist, the audit record, and the no-fabrication guarantee.
 *
 * The happy path is exercised with a test double because the real client
 * deliberately refuses to guess the contract (ASSISTANT_CONTRACT_PENDING).
 */
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

        // Nothing may leave the platform unless both are set (§12.5).
        config([
            'services.assistant.enabled' => false,
            'services.assistant.approval_reference' => null,
        ]);

        /*
         * An interaction records the configuration_version it was produced
         * under, resolved from the active AlgorithmConfiguration exactly as
         * the intelligence endpoints do. Without one there is no truthful
         * answer to "which configuration is this explanation based on", so
         * the assistant refuses rather than inventing a version — see
         * test_it_refuses_when_no_active_configuration_exists.
         */
        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => ['skill_scale' => [0, 5]],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------------ authorization

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

    // ------------------------------------------------------------- scope

    public function test_an_intent_outside_the_permitted_scope_is_refused(): void
    {
        [$user] = $this->createLearner();

        Sanctum::actingAs($user);

        // §12.5 permits explanation and guidance. Writing a deliverable on
        // the learner's behalf is explicitly outside that scope.
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

    // ------------------------------------------------ §12.5 governance gate

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

        // Mirrors REC-06 / BR-REC-07: not enableable without approval
        // metadata. A bare flag is not enough.
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
        /*
         * Both later failure conditions are present at once: the assistant
         * is disabled AND no active configuration exists. The gate must win.
         * If it were consulted late — as it originally was, inside the
         * client — the missing configuration would surface first and the
         * caller would be handed the wrong code and the wrong status.
         */
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

    // ------------------------------------------------ configuration binding

    public function test_it_refuses_when_no_active_configuration_exists(): void
    {
        AlgorithmConfiguration::query()->update(['status' => 'retired']);

        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        // The gate is open here, so the refusal genuinely comes from the
        // missing configuration and not from governance.
        $this->approveGate();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_CONFIGURATION_INVALID');

        // Nothing was dispatched and nothing needed auditing: the request
        // never reached orchestration, so no PENDING row is left behind.
        $this->assertSame(0, AssistantInteraction::query()->count());
    }

    // ------------------------------------------------------- transport wiring

    public function test_with_the_gate_open_but_no_service_token_it_fails_locally(): void
    {
        /*
         * The transport must refuse before any network I/O when the credential
         * is missing, so a misconfigured deployment can never call the service
         * unauthenticated. This replaces the old ASSISTANT_CONTRACT_PENDING
         * assertion: the contract is no longer pending, but the failure mode it
         * guarded — reaching the network without a credential — still is.
         */
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

    // ------------------------------------------------------------ audit

    public function test_a_failed_interaction_is_still_audited(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->configureTransport();

        // The service itself is down. The attempt must still be auditable.
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

        /*
         * §12.5 data minimisation: the conversation is never stored, on success
         * or on failure. Relaying the reply to the caller is deliberately not
         * the same as keeping it — this assertion is what would catch someone
         * adding a `reply` column and quietly populating it.
         */
        $this->assertStringNotContainsString('learner@example.com', $serialised);
        $this->assertStringNotContainsString($question, $serialised);
        $this->assertStringNotContainsString($this->assistantResponse()['reply'], $serialised);

        // A provenance fingerprint IS stored instead.
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

        // The approved snapshot travels as structured data, not as prose the
        // model would have to interpret.
        $this->assertSame($profile->id, $call['context']['student_profile_id']);
    }

    // --------------------------------------------------------- happy path

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
        /*
         * The regression this exists for: the endpoint used to drop the reply
         * entirely, so the UI had nothing to render even when the assistant had
         * answered correctly. Relaying is not the same as storing.
         */
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
        /*
         * The service returns HTTP 200 with its fallback text when every
         * provider in its chain fails, so the status code cannot carry the
         * failure — `provider_used: null` is the only machine-readable signal.
         * Recording that as SUCCEEDED would log a total outage as a working
         * assistant.
         *
         * The reply is still relayed. It is a real, handled outcome, and the
         * learner reading "temporarily unavailable" beats an error state; what
         * changes is the audit record, not the user's experience.
         */
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

        // A token shipped to a browser is public. It must never appear in a
        // response the learner can read.
        $this->assertStringNotContainsString('test-service-token', (string) $response->getContent());
    }

    // ------------------------------------------------------------ helpers

    private function approveGate(): void
    {
        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => 'GOV-APPROVAL-TEST-1',
        ]);
    }

    /**
     * Point the transport at a fake service with a known credential.
     *
     * Set explicitly rather than inherited from .env, so a developer with a real
     * ASSISTANT_SERVICE_TOKEN gets the same results as CI.
     */
    private function configureTransport(): void
    {
        config([
            'services.assistant.url' => 'http://assistant.test',
            'services.assistant.path' => '/chat',
            'services.assistant.service_token' => 'test-service-token',
            'services.assistant.timeout' => 5,
        ]);
    }

    /**
     * A well-formed response from the assistant service, per its real contract.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assistantResponse(array $overrides = []): array
    {
        return array_merge([
            'reply' => 'Your readiness score is weighted across three dimensions.',
            'provider_used' => 'gemini',
            'prompt_version' => 'v1',
            'timestamp' => '2026-09-22T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * Swap the transport for a stub returning a fixed response, and hand it back
     * so the test can inspect what the orchestrator actually sent.
     *
     * The wire contract itself is covered by AssistantClientTest against
     * Http::fake(); this exists so orchestration can be tested without also
     * pretending to be the service.
     */
    private function fakeClientReturning(array $response): AssistantClient
    {
        $client = new class($response) extends AssistantClient
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly array $stub) {}

            public function ask(
                string $userId,
                string $message,
                array $contextSnapshot,
                string $requestId,
            ): array {
                $this->calls[] = [
                    'user_id' => $userId,
                    'message' => $message,
                    'context' => $contextSnapshot,
                    'request_id' => $requestId,
                ];

                return $this->stub;
            }
        };

        $this->instance(AssistantClient::class, $client);

        return $client;
    }

    /**
     * @return array{0: User, 1: StudentProfile}
     */
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
