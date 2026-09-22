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

    // --------------------------------------------------- contract pending

    public function test_with_the_gate_open_the_missing_contract_is_reported_honestly(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ASSISTANT_CONTRACT_PENDING');
    }

    // ------------------------------------------------------------ audit

    public function test_a_failed_interaction_is_still_audited(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => 'Why is my readiness score low?',
        ])->assertStatus(503);

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame($profile->id, $interaction->student_profile_id);
        $this->assertSame('explain_readiness', $interaction->intent);
        $this->assertSame(AssistantInteraction::STATUS_FAILED, $interaction->response_status);
        $this->assertSame('ASSISTANT_CONTRACT_PENDING', $interaction->failure_code);
        $this->assertNotNull($interaction->configuration_version);
        $this->assertNotEmpty($interaction->request_id);
    }

    public function test_the_audit_row_stores_no_conversation_content(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $question = 'My email is learner@example.com — why is my readiness low?';

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_readiness',
            'question' => $question,
        ])->assertStatus(503);

        $interaction = AssistantInteraction::query()->firstOrFail();

        // §12.5 data minimisation: the question is never persisted.
        $serialised = json_encode($interaction->getAttributes());

        $this->assertStringNotContainsString('learner@example.com', (string) $serialised);
        $this->assertStringNotContainsString($question, (string) $serialised);

        // A provenance fingerprint IS stored instead.
        $this->assertStringStartsWith('ctx-', (string) $interaction->context_reference);
    }

    public function test_the_request_id_is_correlated_through_to_the_audit_row(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();

        $this->postJson(
            '/api/v1/assistant/ask',
            ['intent' => 'explain_roadmap', 'question' => 'What should I do next?'],
            ['X-Request-ID' => 'req-correlation-1'],
        )->assertStatus(503);

        $this->assertSame(
            'req-correlation-1',
            AssistantInteraction::query()->firstOrFail()->request_id,
        );
    }

    // --------------------------------------------------------- happy path

    public function test_a_permitted_intent_is_orchestrated_end_to_end(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning(['algorithm_version' => 'assistant-v1']);

        $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.intent', 'explain_skill_gap')
            ->assertJsonPath('data.response_status', AssistantInteraction::STATUS_SUCCEEDED)
            ->assertJsonPath('data.algorithm_version', 'assistant-v1');

        $interaction = AssistantInteraction::query()->firstOrFail();

        $this->assertSame($profile->id, $interaction->student_profile_id);
        $this->assertSame(AssistantInteraction::STATUS_SUCCEEDED, $interaction->response_status);
        $this->assertNull($interaction->failure_code);
    }

    public function test_the_response_never_contains_a_generated_answer(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->approveGate();
        $this->fakeClientReturning([
            'algorithm_version' => 'assistant-v1',
            'answer' => 'You should focus on SQL first.',
        ]);

        $response = $this->postJson('/api/v1/assistant/ask', [
            'intent' => 'explain_skill_gap',
            'question' => 'Which gap should I close first?',
        ])->assertStatus(201);

        // Laravel stores no conversation content and echoes none back.
        $this->assertNull($response->json('data.answer'));
        $this->assertStringNotContainsString(
            'You should focus on SQL first.',
            (string) $response->getContent(),
        );
    }

    // ------------------------------------------------------------ helpers

    private function approveGate(): void
    {
        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => 'GOV-APPROVAL-TEST-1',
        ]);
    }

    private function fakeClientReturning(array $response): void
    {
        $this->instance(AssistantClient::class, new class($response) extends AssistantClient
        {
            public function __construct(private readonly array $stub) {}

            public function ask(array $contextSnapshot, string $question, string $requestId): array
            {
                return $this->stub;
            }
        });
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
