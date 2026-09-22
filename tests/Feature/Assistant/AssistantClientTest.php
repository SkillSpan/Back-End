<?php

namespace Tests\Feature\Assistant;

use App\Exceptions\AssistantException;
use App\Services\Assistant\AssistantClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US-REC-01 — the Laravel -> FastAPI transport, tested against a faked service.
 *
 * These assert the *wire* contract: what we send, what we read back, and how
 * every failure maps to a stable code. AssistantAskTest covers orchestration
 * with the transport stubbed; this file is the other half, where the stub would
 * otherwise hide a mismatch with the real service.
 *
 * The contract is the one the service actually implements:
 *
 *     POST /chat  { user_id, message, context? }
 *             -> { reply, provider_used, timestamp, prompt_version }
 *
 * No database is involved, so this does not use RefreshDatabase.
 */
class AssistantClientTest extends TestCase
{
    private const SERVICE_URL = 'http://assistant.test';

    private const SERVICE_TOKEN = 'test-service-token';

    protected function setUp(): void
    {
        parent::setUp();

        // The §12.5 gate must be open for the transport to be reachable at all.
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function response(array $overrides = []): array
    {
        return array_merge([
            'reply' => 'Your readiness score is weighted across three dimensions.',
            'provider_used' => 'gemini',
            'prompt_version' => 'v1',
            'timestamp' => '2026-09-22T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function ask(array $snapshot = ['student_profile_id' => 7]): array
    {
        return $this->client()->ask('42', 'Why is my score low?', $snapshot, 'req-1');
    }

    // ------------------------------------------------------------ what we send

    public function test_it_sends_the_documented_contract_payload(): void
    {
        Http::fake([self::SERVICE_URL.'/chat' => Http::response($this->response(), 200)]);

        $this->ask();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // Exactly the three keys the service declares, and nothing else.
            $this->assertSame(['user_id', 'message', 'context'], array_keys($body));
            $this->assertSame('42', $body['user_id']);
            $this->assertSame('Why is my score low?', $body['message']);

            return true;
        });
    }

    public function test_it_does_not_send_the_old_invented_field_names(): void
    {
        /*
         * The previous revision of this client assumed a contract nobody had
         * agreed: { context_snapshot, question, request_id }. The service accepts
         * none of those, so every call would have been a 422. This is the
         * assertion that keeps the real shape from drifting back.
         */
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
            // Structured decision data, not a prose paraphrase the model would
            // have to interpret — REC-07 forbids inventing a second description
            // of the learner's record.
            $this->assertStringContainsString('"student_profile_id": 7', $context);
            $this->assertStringContainsString('"score": 61.5', $context);

            return true;
        });
    }

    public function test_it_attaches_the_service_token_server_side(): void
    {
        /*
         * The credential is Laravel's, not the learner's: a Sanctum token would
         * authenticate a person to the service, which is not what this is. And
         * it is attached here, server-side, so it can never reach a browser.
         */
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

    // ----------------------------------------------------------- what we read

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
        /*
         * The service answers HTTP 200 with its fallback text when every
         * provider fails, so there is nothing to throw on here — the transport
         * reports what it received and the orchestrator decides. Deciding in the
         * transport would hide the distinction from the audit trail.
         */
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

    // ----------------------------------------------------------- failure modes

    public function test_it_refuses_to_send_without_a_governance_approval(): void
    {
        /*
         * §12.5, enforced at the transport rather than only at the service
         * layer, so a direct call to this class can never put learner data on
         * the wire.
         */
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
            // No explicit token, so this resolves from config — which is the
            // path a real deployment takes when the env var is missing.
            (new AssistantClient(baseUrl: self::SERVICE_URL, timeout: 5))
                ->ask('42', 'Why?', [], 'req-1');

            $this->fail('Expected a missing credential to fail locally.');
        } catch (AssistantException $e) {
            $this->assertSame('ASSISTANT_NOT_CONFIGURED', $e->codeName);
        }

        // Never called unauthenticated.
        Http::assertNothingSent();
    }

    public function test_a_rejected_credential_is_reported_as_our_misconfiguration(): void
    {
        /*
         * A 401 from the service means OUR token is wrong, not the learner's.
         * Mapping it to 401 would blame the learner's session for a deployment
         * fault; 503 with a distinct code points at the actual fix, which is
         * making ASSISTANT_SERVICE_TOKEN match the service's SERVICE_TOKEN.
         */
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
        /*
         * A 200 with no reply would otherwise be relayed as an empty string,
         * handing the learner a blank answer where an explanation belongs.
         */
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
