<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data Science (FastAPI) baseline request/response contract, pinned by test.
 *
 * The FastAPI `BaselineRequest` schema is the source of truth:
 *
 *   student_profile_id:int, user_id:int, career_role_id:int,
 *   assessment_version:str,
 *   questions:[{question_id:int, question_type:"single_choice"|"scale",
 *               options:[str], correct_answer:str|null,
 *               scoring_rule:dict[str,float]|null, weight:float,
 *               skill_mappings:[{skill_id:int, skill_slug:str, weight:float}]}],
 *   responses:[{question_id:int, answer:str}]
 *
 * The public API still accepts the client's string `item_id` ("sql-001");
 * Laravel translates it to `baseline_assessment_item_id` (int) on the way
 * out. These tests are the executable specification of that translation.
 */
class BaselineDataScienceContractTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create([
            'name' => 'Learner', 'slug' => 'learner', 'description' => '',
        ]);

        Config::set('services.data_science.baseline.enabled', true);
        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);
        Config::set('services.data_science.service_token', 'test-service-token');
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound request shape
    |--------------------------------------------------------------------------
    */

    public function test_request_contains_exactly_the_documented_fields(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment, $role] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);
        $this->fakeIntelligence(['sql', 'python']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        $payload = $this->sentPayload();

        // Exactly the BaselineRequest fields — nothing else.
        $keys = array_keys($payload);
        sort($keys);

        $this->assertSame([
            'assessment_version',
            'career_role_id',
            'questions',
            'responses',
            'student_profile_id',
            'user_id',
        ], $keys);

        // The retired contract must be gone entirely.
        $this->assertArrayNotHasKey('question_ids', $payload);
        $this->assertArrayNotHasKey('career_role_version', $payload);

        $this->assertSame((int) $assessment->student_profile_id, $payload['student_profile_id']);
        $this->assertSame((int) $assessment->studentProfile->user_id, $payload['user_id']);
        $this->assertSame('v1.0', $payload['assessment_version']);
        $this->assertSame((int) $role->id, $payload['career_role_id']);

        $this->assertNotEmpty($payload['questions']);
        $this->assertNotEmpty($payload['responses']);

        // Each question carries exactly the BaselineQuestion fields.
        foreach ($payload['questions'] as $question) {
            $questionKeys = array_keys($question);
            sort($questionKeys);

            $this->assertSame([
                'correct_answer',
                'options',
                'question_id',
                'question_type',
                'scoring_rule',
                'skill_mappings',
                'weight',
            ], $questionKeys);

            // Internal/legacy fields must never leak upstream.
            $this->assertArrayNotHasKey('item_id', $question);
            $this->assertArrayNotHasKey('question_text', $question);
            $this->assertArrayNotHasKey('importance_weight', $question);
            $this->assertArrayNotHasKey('is_critical', $question);

            $this->assertIsInt($question['question_id']);
            $this->assertIsString($question['question_type']);
            $this->assertIsArray($question['options']);
            $this->assertIsFloat($question['weight']);

            $this->assertNotEmpty($question['skill_mappings']);

            foreach ($question['skill_mappings'] as $mapping) {
                $mappingKeys = array_keys($mapping);
                sort($mappingKeys);

                $this->assertSame(['skill_id', 'skill_slug', 'weight'], $mappingKeys);
                $this->assertIsInt($mapping['skill_id']);
                $this->assertIsString($mapping['skill_slug']);
            }
        }

        foreach ($payload['responses'] as $response) {
            $this->assertSame(['question_id', 'answer'], array_keys($response));
            $this->assertIsInt($response['question_id']);
        }

        // Auth + tracing headers are unchanged.
        Http::assertSent(function ($request) {
            return ($request->header('Authorization')[0] ?? null) === 'Bearer test-service-token'
                && ! empty($request->header('X-Request-ID'));
        });
    }

    /**
     * The literal translation this fix exists for:
     *
     *   {"question_id":"sql-001"}  ->  {"question_id": 27}
     */
    public function test_frontend_item_id_is_translated_to_the_numeric_assessment_item_id(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        $itemId = (int) $assessment->questionSnapshots()->value('baseline_assessment_item_id');

        $this->assertGreaterThan(0, $itemId);

        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        $payload = $this->sentPayload();

        $this->assertSame($itemId, $payload['questions'][0]['question_id']);
        $this->assertSame($itemId, $payload['responses'][0]['question_id']);
        $this->assertSame('A', $payload['responses'][0]['answer']);

        // The public identifier must not appear anywhere in the outbound body.
        $this->assertStringNotContainsString(
            'sql-001',
            json_encode($payload, JSON_THROW_ON_ERROR),
            'The client item_id must never be sent to the intelligence service.'
        );
    }

    public function test_question_and_response_ids_are_integers_and_match(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);
        $this->fakeIntelligence(['sql', 'python']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        $payload = $this->sentPayload();

        $questionIds = collect($payload['questions'])->pluck('question_id')->all();
        $responseIds = collect($payload['responses'])->pluck('question_id')->all();

        foreach ($questionIds as $id) {
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id);
        }

        foreach ($responseIds as $id) {
            $this->assertIsInt($id);
        }

        // No duplicates, and every response belongs to the question set.
        $this->assertSame($questionIds, array_values(array_unique($questionIds)));
        $this->assertSame([], array_values(array_diff($responseIds, $questionIds)));

        // The ids are the assessment item ids, not the snapshot row ids.
        $expected = $assessment->questionSnapshots()
            ->pluck('baseline_assessment_item_id')
            ->map(fn ($id) => (int) $id)
            ->sort()->values()->all();

        sort($questionIds);

        $this->assertSame($expected, $questionIds);
    }

    public function test_single_choice_question_carries_correct_answer_and_null_scoring_rule(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $question = $this->sentPayload()['questions'][0];

        $this->assertSame('single_choice', $question['question_type']);
        $this->assertSame(['A', 'B', 'C', 'D'], $question['options']);
        $this->assertSame('A', $question['correct_answer']);
        $this->assertNull($question['scoring_rule']);
        $this->assertContains($question['correct_answer'], $question['options']);
    }

    public function test_scale_question_carries_scoring_rule_and_null_correct_answer(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $scoringRule = ['1' => 0.0, '2' => 0.25, '3' => 0.5, '4' => 0.75, '5' => 1.0];

        [$assessment] = $this->start(
            ['sql'],
            ['sql-scale-001'],
            [
                'item_type' => 'scale',
                'options' => ['1', '2', '3', '4', '5'],
                'correct_answer' => null,
                'scoring_rule' => $scoringRule,
            ],
        );

        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-scale-001', 'answer' => '4']],
        ])->assertStatus(200);

        $question = $this->sentPayload()['questions'][0];

        $this->assertSame('scale', $question['question_type']);
        $this->assertNull($question['correct_answer']);
        $this->assertSame($scoringRule, $question['scoring_rule']);
        $this->assertSame(['1', '2', '3', '4', '5'], $question['options']);
    }

    public function test_question_weight_comes_from_the_item_not_the_role_skill_importance_weight(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        // Role skill importance weight = 0.9, item weight = 0.4.
        [$assessment] = $this->start(['sql'], ['sql-001'], ['weight' => 0.4]);
        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $question = $this->sentPayload()['questions'][0];

        $this->assertSame(0.4, $question['weight']);
        $this->assertNotSame(0.9, $question['weight']);

        // The role's importance weight still lives on the snapshot, unused by
        // the outbound contract.
        $this->assertSame(
            0.9,
            (float) $assessment->questionSnapshots()->value('importance_weight')
        );
    }

    public function test_skill_mappings_carry_the_snapshot_skill_and_a_full_weight(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $skill = Skill::where('slug', 'sql')->firstOrFail();
        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $mappings = $this->sentPayload()['questions'][0]['skill_mappings'];

        $this->assertCount(1, $mappings);
        $this->assertSame((int) $skill->id, $mappings[0]['skill_id']);
        $this->assertSame('sql', $mappings[0]['skill_slug']);
        $this->assertSame(1.0, $mappings[0]['weight']);
    }

    /*
    |--------------------------------------------------------------------------
    | Fail-closed guards (no HTTP call may be made)
    |--------------------------------------------------------------------------
    */

    public function test_unsupported_text_question_never_reaches_the_intelligence_service(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(
            ['sql'],
            ['sql-text-001'],
            [
                'item_type' => 'text',
                'options' => [],
                'correct_answer' => 'A model answer',
                'scoring_rule' => null,
            ],
        );

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-text-001', 'answer' => 'Any free text']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BASELINE_QUESTION_TYPE_UNSUPPORTED');

        Http::assertNothingSent();

        // The assessment stays resumable; nothing was scored.
        $this->assertSame('in_progress', $assessment->fresh()->status);
        $this->assertSame(0, SkillEvaluation::count());
    }

    public function test_invalid_scoring_configuration_fails_before_the_http_call(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        // scoring_rule keys do not match the options list.
        [$assessment] = $this->start(
            ['sql'],
            ['sql-scale-001'],
            [
                'item_type' => 'scale',
                'options' => ['1', '2', '3', '4', '5'],
                'correct_answer' => null,
                'scoring_rule' => ['1' => 0.5, '2' => 0.5],
            ],
        );

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-scale-001', 'answer' => '3']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BASELINE_INTELLIGENCE_PAYLOAD_INVALID');

        Http::assertNothingSent();
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    public function test_single_choice_without_a_correct_answer_fails_closed(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(
            ['sql'],
            ['sql-001'],
            ['correct_answer' => null],
        );

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BASELINE_INTELLIGENCE_PAYLOAD_INVALID');

        Http::assertNothingSent();
    }

    public function test_missing_snapshot_item_id_fails_closed_without_scoring(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        // Simulates a legacy row detached by ON DELETE SET NULL.
        BaselineQuestionSnapshot::query()
            ->where('baseline_assessment_id', $assessment->id)
            ->update(['baseline_assessment_item_id' => null]);

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSESSMENT_SNAPSHOT_INCOMPLETE');

        Http::assertNothingSent();
        $this->assertSame(0, SkillEvaluation::count());
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot integrity of the scoring inputs
    |--------------------------------------------------------------------------
    */

    public function test_editing_the_live_item_does_not_change_the_outbound_scoring_data(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001'], ['weight' => 0.5]);

        // Rewrite the live question after the assessment started.
        BaselineAssessmentItem::query()
            ->where('item_id', 'sql-001')
            ->update([
                'correct_answer' => 'D',
                'options' => json_encode(['X', 'Y']),
                'weight' => 0.900,
                'item_type' => 'single_choice',
            ]);

        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $question = $this->sentPayload()['questions'][0];

        // Frozen values from assessment start.
        $this->assertSame('A', $question['correct_answer']);
        $this->assertSame(['A', 'B', 'C', 'D'], $question['options']);
        $this->assertSame(0.5, $question['weight']);
        $this->assertSame('sql', $question['skill_mappings'][0]['skill_slug']);
    }

    public function test_legacy_snapshot_without_frozen_scoring_uses_a_verified_live_item(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001'], ['weight' => 0.7]);

        // Simulate a snapshot created before scoring was frozen.
        $this->stripFrozenScoring($assessment);

        Log::spy();
        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $question = $this->sentPayload()['questions'][0];

        $this->assertSame('A', $question['correct_answer']);
        $this->assertSame(0.7, $question['weight']);
        $this->assertSame('sql', $question['skill_mappings'][0]['skill_slug']);

        // The fallback is logged with ids only — never answers.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'missing frozen scoring data')
                && ($context['assessment_id'] ?? null) === (int) $assessment->id
                && ! array_key_exists('answers', $context)
                && ! array_key_exists('correct_answer', $context))
            ->once();
    }

    public function test_legacy_snapshot_with_a_changed_live_item_fails_closed(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        $this->stripFrozenScoring($assessment);

        // The live item no longer matches what the learner was shown.
        BaselineAssessmentItem::query()
            ->where('item_id', 'sql-001')
            ->update(['options' => json_encode(['X', 'Y', 'Z'])]);

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSESSMENT_SNAPSHOT_INCOMPLETE')
            ->assertJsonPath('details.reasons', ['options_mismatch']);

        Http::assertNothingSent();
        $this->assertSame(0, SkillEvaluation::count());
    }

    public function test_legacy_snapshot_whose_live_item_was_deleted_fails_closed(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        $this->stripFrozenScoring($assessment);

        BaselineAssessmentItem::query()->where('item_id', 'sql-001')->delete();

        Http::fake();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSESSMENT_SNAPSHOT_INCOMPLETE');

        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound response shape
    |--------------------------------------------------------------------------
    */

    public function test_skills_key_is_required_and_skill_evaluations_is_not_accepted(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v2.0',
                'skill_evaluations' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, SkillEvaluation::count());
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    public function test_successful_response_persists_skill_evaluations_and_algorithm_version(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v2.0',
                'assessment_version' => 'v1.0',
                'overall_score' => 87.5,
                'skills' => [
                    ['skill_id' => Skill::where('slug', 'sql')->value('id'), 'slug' => 'sql', 'level' => 4.38, 'confidence' => 1.0],
                    ['skill_id' => Skill::where('slug', 'python')->value('id'), 'slug' => 'python', 'level' => 3.0, 'confidence' => 0.5],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200)->assertJsonPath('data.status', 'completed');

        $this->assertSame(2, SkillEvaluation::count());
        $this->assertSame(
            ['baseline-v2.0'],
            SkillEvaluation::query()->pluck('algorithm_version')->unique()->values()->all()
        );

        // The raw service payload is preserved on the assessment result.
        $this->assertSame(
            87.5,
            (float) $assessment->fresh()->result['overall_score']
        );
    }

    public function test_fastapi_422_is_surfaced_without_echoing_the_upstream_body(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'detail' => [[
                    'type' => 'int_parsing',
                    'loc' => ['body', 'responses', 0, 'question_id'],
                    'msg' => 'Input should be a valid integer',
                    'input' => 'LEAK-PROBE-ANSWER-9f3a',
                ]],
            ], 422),
        ]);

        Log::spy();

        $response = $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_VALIDATION_ERROR')
            ->assertJsonMissingPath('details');

        $this->assertStringNotContainsString('LEAK-PROBE-ANSWER-9f3a', $response->getContent());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Baseline intelligence request failed')
                && ! str_contains($context['failure_reason'] ?? '', 'LEAK-PROBE-ANSWER-9f3a'))
            ->once();

        // Nothing persisted; the assessment is still resumable.
        $this->assertSame(0, SkillEvaluation::count());
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    /**
     * A failing upstream response must not put its body in our log.
     */
    public function test_a_5xx_does_not_copy_the_upstream_body_into_the_log(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response(
                "Traceback (most recent call last):\n  payload = {'email': 'LEAK-PROBE-VALUE-9f3a'}",
                500,
            ),
        ]);

        Log::spy();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(503);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Baseline intelligence request failed')
                && ! str_contains($context['failure_reason'] ?? '', 'LEAK-PROBE-VALUE-9f3a')
                && str_contains($context['failure_reason'] ?? '', 'upstream body omitted'))
            ->once();
    }

    /**
     * The counterpart: the documented `{"detail": "..."}` explanation IS kept,
     * because that string is what actually diagnoses the failure.
     */
    public function test_a_5xx_keeps_the_documented_detail_message_in_the_log(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response(['detail' => 'unknown assessment_version'], 503),
        ]);

        Log::spy();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(503);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Baseline intelligence request failed')
                && str_contains($context['failure_reason'] ?? '', 'unknown assessment_version'))
            ->once();
    }

    public function test_confidence_may_be_omitted_and_defaults_safely(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v2.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 2.5],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $evaluation = SkillEvaluation::where('skill_id', $sql->id)->firstOrFail();

        $this->assertSame(2.5, (float) $evaluation->level);
    }

    public function test_evaluation_ownership_comes_from_the_assessment_not_the_service_response(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        $victimProfileId = $profile->id + 9999;
        $victimUserId = $user->id + 9999;

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v2.0',
                'student_profile_id' => $victimProfileId,
                'user_id' => $victimUserId,
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $evaluation = SkillEvaluation::firstOrFail();

        $this->assertSame((int) $profile->id, (int) $evaluation->student_profile_id);
        $this->assertNotSame($victimProfileId, (int) $evaluation->student_profile_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The single outbound request recorded by the fake HTTP client.
     *
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $sent = Http::recorded();

        $this->assertCount(1, $sent, 'Exactly one intelligence request is expected.');

        return $sent->first()[0]->data();
    }

    private function stripFrozenScoring(BaselineAssessment $assessment): void
    {
        BaselineQuestionSnapshot::query()
            ->where('baseline_assessment_id', $assessment->id)
            ->update([
                'correct_answer' => null,
                'scoring_rule' => null,
                'weight' => null,
                'skill_slug' => null,
            ]);
    }

    /**
     * @param  array<int, string>  $skillSlugs
     * @param  array<int, string>  $itemIds
     * @param  array<string, mixed>  $overrides  Item column overrides.
     * @return array{0: BaselineAssessment, 1: CareerRole}
     */
    private function start(array $skillSlugs, array $itemIds, array $overrides = []): array
    {
        $role = CareerRole::create([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        foreach ($skillSlugs as $index => $slug) {
            $skill = Skill::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst($slug), 'status' => 'active'],
            );

            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3.0,
                // Deliberately different from the item weight so the two can
                // never be confused in the outbound payload.
                'importance_weight' => round(0.9 - ($index * 0.1), 3),
                'is_critical' => $index === 0,
            ]);

            BaselineAssessmentItem::updateOrCreate(
                ['assessment_version' => 'v1.0', 'item_id' => $itemIds[$index]],
                array_merge([
                    'item_type' => 'single_choice',
                    'question_text' => "Baseline prompt for {$itemIds[$index]}.",
                    'skill_id' => $skill->id,
                    'options' => ['A', 'B', 'C', 'D'],
                    'correct_answer' => 'A',
                    'scoring_rule' => null,
                    'weight' => 1.000,
                    'is_active' => true,
                ], $overrides),
            );
        }

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        return [BaselineAssessment::with('questionSnapshots')->findOrFail($assessmentId), $role];
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function fakeIntelligence(array $slugs): void
    {
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v2.0',
                'skills' => collect($slugs)->map(fn (string $slug) => [
                    'slug' => $slug,
                    'level' => 3.0,
                    'confidence' => 0.8,
                ])->all(),
            ], 200),
        ]);
    }

    private function createLearner(): array
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
