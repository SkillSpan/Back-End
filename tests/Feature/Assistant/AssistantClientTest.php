<?php

namespace Tests\Feature\Assistant;

use App\Exceptions\AssistantException;
use App\Services\Assistant\AssistantClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantClientTest extends TestCase
{
    private const SERVICE_URL = 'http://assistant.test';

    private const SERVICE_TOKEN = 'test-service-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.assistant.enabled' => true,
            'services.assistant.approval_reference' => 'GOV-TRANSPORT-TEST',
            'services.assistant.url' => self::SERVICE_URL,
            'services.assistant.path' => '/chat',
            'services.assistant.service_token' => self::SERVICE_TOKEN,
            'services.assistant.timeout' => 5,
        ]);
    }

    private function client(): AssistantClient
    {
        return new AssistantClient(
            serviceToken: self::SERVICE_TOKEN,
            baseUrl: self::SERVICE_URL,
            timeout: 5,
        );
    }

    private function response(array $overrides = []): array
    {
        return array_merge([
            'reply' => 'Your readiness score is weighted across three dimensions.',
            'provider_used' => 'gemini',
            'prompt_version' => 'v1',
            'timestamp' => '2026-09-22T10:00:00+00:00',
        ], $overrides);
    }

    private function ask(
        array $snapshot = ['student_profile_id' => 7],
        string $intent = 'explain_readiness',
    ): array {
        return $this->client()->ask('42', 'Why is my score low?', $snapshot, 'req-1', $intent);
    }

    public function test_it_sends_the_documented_contract_payload(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame(['user_id', 'message', 'intent', 'context'], array_keys($body));
            $this->assertSame('42', $body['user_id']);
            $this->assertSame('Why is my score low?', $body['message']);
            $this->assertSame('explain_readiness', $body['intent']);

            return true;
        });
    }

    public function test_it_sends_the_intent_the_caller_validated_verbatim(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask(intent: 'project_bounded_help');

        Http::assertSent(fn (Request $request) => $request->data()['intent'] === 'project_bounded_help');
    }

    public function test_it_does_not_send_the_old_invented_field_names(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertArrayNotHasKey('context_snapshot', $body);
            $this->assertArrayNotHasKey('question', $body);
            $this->assertArrayNotHasKey('request_id', $body);

            return true;
        });
    }

    public function test_it_sends_the_context_snapshot_as_structured_json(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask([
            'student_profile_id' => 7,
            'readiness' => ['available' => true, 'score' => 61.5],
        ]);

        Http::assertSent(function (Request $request) {
            $context = $request->data()['context'];

            $this->assertIsString($context);
            $this->assertStringContainsString('"student_profile_id": 7', $context);
            $this->assertStringContainsString('"score": 61.5', $context);

            return true;
        });
    }

    public function test_the_context_is_always_present_so_service_retrieval_never_runs(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask([
            'student_profile_id' => 7,
            'readiness' => ['available' => false, 'reason' => 'No readiness result.'],
        ]);

        Http::assertSent(function (Request $request) {
            $context = $request->data()['context'];

            $this->assertIsString($context);
            $this->assertNotSame('', trim($context));
            $this->assertStringContainsString('"available": false', $context);

            return true;
        });
    }

    public function test_it_attaches_the_service_token_server_side(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask();

        Http::assertSent(fn (Request $request) => $request->hasHeader(
            'Authorization',
            'Bearer '.self::SERVICE_TOKEN,
        ));
    }

    public function test_it_correlates_the_request_with_the_request_id(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask();

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-ID', 'req-1'));
    }

    public function test_it_reads_the_reply_provider_and_prompt_version(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $result = $this->ask();

        $this->assertSame('Your readiness score is weighted across three dimensions.', $result['reply']);
        $this->assertSame('gemini', $result['provider_used']);
        $this->assertSame('v1', $result['prompt_version']);
        $this->assertSame('2026-09-22T10:00:00+00:00', $result['timestamp']);
    }

    public function test_a_total_provider_outage_is_returned_not_thrown(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response([
            'reply' => "I'm temporarily unavailable. Please try again shortly.",
            'provider_used' => null,
        ]), 200)]);

        $result = $this->ask();

        $this->assertNull($result['provider_used']);
        $this->assertSame("I'm temporarily unavailable. Please try again shortly.", $result['reply']);
    }

    public function test_a_missing_prompt_version_is_null_rather_than_an_error(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response([
            'reply' => 'ok',
            'provider_used' => 'groq',
        ], 200)]);

        $result = $this->ask();

        $this->assertNull($result['prompt_version']);
        $this->assertSame('groq', $result['provider_used']);
    }

    public function test_it_maps_the_wire_status_onto_answer_status(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response([
            'status' => 'insufficient_context',
            'grounded' => false,
        ]), 200)]);

        $result = $this->ask();

        $this->assertSame('insufficient_context', $result['answer_status']);
        $this->assertFalse($result['grounded']);
    }

    public function test_an_answered_status_is_read_as_answered(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response([
            'status' => 'answered',
            'grounded' => true,
        ]), 200)]);

        $result = $this->ask();

        $this->assertSame('answered', $result['answer_status']);
        $this->assertTrue($result['grounded']);
    }

    public function test_a_missing_status_is_null_rather_than_a_guess(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $result = $this->ask();

        $this->assertNull($result['answer_status']);
        $this->assertFalse($result['grounded']);
    }

    public function test_a_non_boolean_grounded_is_coerced_to_false(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response([
            'grounded' => 'false',
        ]), 200)]);

        $this->assertFalse($this->ask()['grounded']);
    }

    public function test_it_refuses_to_send_without_a_governance_approval(): void
    {
        config(['services.assistant.approval_reference' => null]);

        Http::fake();

        try {
            $this->ask();
            $this->fail('Expected the §12.5 gate to refuse the call.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_APPROVAL_NOT_RECORDED', $e->codeName);
        }

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_send_without_a_service_token(): void
    {
        config(['services.assistant.service_token' => null]);

        Http::fake();

        try {
            (new AssistantClient(baseUrl: self::SERVICE_URL, timeout: 5))
                ->ask('42', 'Why?', [], 'req-1', 'explain_readiness');

            $this->fail('Expected a missing credential to fail locally.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_NOT_CONFIGURED', $e->codeName);
        }

        Http::assertNothingSent();
    }

    public function test_a_rejected_credential_is_reported_as_our_misconfiguration(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response(['detail' => 'nope'], 401)]);

        try {
            $this->ask();
            $this->fail('Expected a rejected credential to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_NOT_CONFIGURED', $e->codeName);
            $this->assertSame(503, $e->status);
        }
    }

    public function test_a_service_side_failure_is_reported_as_unavailable(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response(['detail' => 'boom'], 500)]);

        try {
            $this->ask();
            $this->fail('Expected a 5xx to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_UNAVAILABLE', $e->codeName);
            $this->assertSame(503, $e->status);
        }
    }

    public function test_a_connection_failure_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        try {
            $this->ask();
            $this->fail('Expected a connection failure to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_UNAVAILABLE', $e->codeName);
        }
    }

    public function test_a_validation_rejection_is_reported_as_a_validation_error(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response(['detail' => 'bad'], 422)]);

        try {
            $this->ask();
            $this->fail('Expected a 422 to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_VALIDATION_ERROR', $e->codeName);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_a_success_body_without_a_reply_is_rejected(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response(['provider_used' => 'gemini'], 200)]);

        try {
            $this->ask();
            $this->fail('Expected a reply-less body to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_INVALID_RESPONSE', $e->codeName);
        }
    }

    public function test_a_non_json_body_is_rejected(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response('not json', 200)]);

        try {
            $this->ask();
            $this->fail('Expected a non-JSON body to throw.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_INVALID_RESPONSE', $e->codeName);
        }
    }
}
