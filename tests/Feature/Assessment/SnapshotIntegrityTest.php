<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task A — snapshot integrity.
 *
 * A baseline question snapshot is a historical record of exactly what a
 * learner was shown and how their answers are validated. Once created it
 * must survive arbitrary mutation of the source data it was copied from:
 *
 *   - deleting a question from the bank must NOT delete the snapshot;
 *   - editing a question's text/options/type must NOT change the
 *     snapshot;
 *   - deactivating a question must NOT invalidate an in-flight
 *     assessment;
 *   - deleting a skill must NOT silently cascade the snapshot away.
 *
 * Retrieval and submission must keep working from the frozen snapshot
 * alone, even after the source row is gone.
 */
class SnapshotIntegrityTest extends TestCase
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
    | Schema / foreign-key design
    |--------------------------------------------------------------------------
    */

    public function test_snapshot_foreign_keys_are_non_destructive(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $byColumn = $this->foreignKeyActions('baseline_question_snapshots');

        // Deleting a source question detaches the snapshot; it does not
        // destroy it.
        $this->assertSame('SET NULL', $byColumn['baseline_assessment_item_id'] ?? null);

        // A skill is a stable taxonomy node: refuse the delete rather
        // than orphan the snapshot's skill mapping.
        $this->assertSame('RESTRICT', $byColumn['skill_id'] ?? null);
    }

    public function test_snapshot_item_reference_is_nullable(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(baseline_question_snapshots)'))
            ->keyBy('name');

        $this->assertFalse(
            (bool) $columns['baseline_assessment_item_id']->notnull,
            'baseline_assessment_item_id must be nullable so ON DELETE SET NULL can write.'
        );
    }

    public function test_parent_assessment_deletion_still_removes_its_own_snapshots(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $assessmentId = $assessment->id;
        $this->assertGreaterThan(
            0,
            BaselineQuestionSnapshot::where('baseline_assessment_id', $assessmentId)->count()
        );

        // The parent assessment owns its snapshot rows — deleting it
        // legitimately removes them (this cascade is intentionally kept).
        $assessment->delete();

        $this->assertSame(
            0,
            BaselineQuestionSnapshot::where('baseline_assessment_id', $assessmentId)->count()
        );
    }

    public function test_deleting_a_skill_is_restricted_while_snapshots_reference_it(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $skillId = (int) $assessment->questionSnapshots()->value('skill_id');

        // RESTRICT: the delete must fail loudly rather than silently
        // cascade the assessment's skill mapping out of existence.
        $this->expectException(QueryException::class);

        Skill::query()->whereKey($skillId)->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Source-question deletion / modification
    |--------------------------------------------------------------------------
    */

    public function test_deleting_a_source_question_does_not_delete_its_snapshot(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $snapshot = $assessment->questionSnapshots()->firstOrFail();
        $snapshotId = $snapshot->id;
        $itemId = $snapshot->baseline_assessment_item_id;

        $this->assertNotNull($itemId);

        // Hard-delete the source question straight through the query
        // builder so Eloquent events cannot mask the DB-level behaviour.
        BaselineAssessmentItem::query()->whereKey($itemId)->delete();

        $stillThere = BaselineQuestionSnapshot::find($snapshotId);

        $this->assertNotNull($stillThere, 'The snapshot must survive deletion of its source question.');
        $this->assertNull(
            $stillThere->baseline_assessment_item_id,
            'The snapshot should be detached from the deleted source question.'
        );

        // Frozen content is intact and still identifies the question.
        $this->assertNotEmpty($stillThere->item_id);
        $this->assertSame('single_choice', $stillThere->item_type);
        $this->assertNotEmpty($stillThere->options);
    }

    public function test_deleting_a_source_question_leaves_frozen_content_unchanged(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $before = $assessment->questionSnapshots()
            ->get()
            ->map(fn (BaselineQuestionSnapshot $s) => [
                'item_id' => $s->item_id,
                'item_type' => $s->item_type,
                'question_text' => $s->question_text,
                'options' => $s->options,
                'skill_id' => (int) $s->skill_id,
            ])
            ->sortBy('item_id')
            ->values()
            ->all();

        $this->assertCount(2, $before);

        // Wipe the entire source bank.
        BaselineAssessmentItem::query()->delete();

        $after = BaselineQuestionSnapshot::query()
            ->where('baseline_assessment_id', $assessment->id)
            ->get()
            ->map(fn (BaselineQuestionSnapshot $s) => [
                'item_id' => $s->item_id,
                'item_type' => $s->item_type,
                'question_text' => $s->question_text,
                'options' => $s->options,
                'skill_id' => (int) $s->skill_id,
            ])
            ->sortBy('item_id')
            ->values()
            ->all();

        $this->assertSame($before, $after, 'Frozen snapshot content must not change when the bank is deleted.');
    }

    public function test_editing_a_source_question_does_not_alter_the_snapshot(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $snapshot = $assessment->questionSnapshots()
            ->where('item_id', 'sql-001')
            ->firstOrFail();

        $frozen = [
            'item_id' => $snapshot->item_id,
            'item_type' => $snapshot->item_type,
            'question_text' => $snapshot->question_text,
            'options' => $snapshot->options,
        ];

        // Rewrite the live question: new text, new options, new type,
        // and deactivate it.
        BaselineAssessmentItem::query()
            ->where('item_id', 'sql-001')
            ->update([
                'question_text' => 'COMPLETELY REWRITTEN PROMPT',
                'options' => json_encode(['X', 'Y', 'Z']),
                'item_type' => 'scale',
                'is_active' => false,
            ]);

        $reloaded = BaselineQuestionSnapshot::findOrFail($snapshot->id);

        $this->assertSame($frozen['item_id'], $reloaded->item_id);
        $this->assertSame($frozen['item_type'], $reloaded->item_type);
        $this->assertSame($frozen['question_text'], $reloaded->question_text);
        $this->assertSame($frozen['options'], $reloaded->options);
    }

    public function test_deactivating_a_source_question_does_not_break_an_inflight_assessment(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        BaselineAssessmentItem::query()->update(['is_active' => false]);

        // Retrieval still returns the frozen question set.
        $this->getJson("/api/v1/baseline-assessments/{$assessment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonCount(2, 'data.questions');
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieval from the frozen snapshot
    |--------------------------------------------------------------------------
    */

    public function test_retrieval_uses_frozen_content_after_source_deletion(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $expected = BaselineQuestionSnapshot::query()
            ->where('baseline_assessment_id', $assessment->id)
            ->get()
            ->mapWithKeys(fn (BaselineQuestionSnapshot $s) => [
                $s->item_id => $s->question_text,
            ])
            ->all();

        BaselineAssessmentItem::query()->delete();

        $response = $this->getJson("/api/v1/baseline-assessments/{$assessment->id}")
            ->assertStatus(200);

        $questions = collect($response->json('data.questions'))->keyBy('item_id');

        $this->assertCount(count($expected), $questions);

        foreach ($expected as $itemId => $text) {
            $this->assertArrayHasKey($itemId, $questions->all(), "Frozen question {$itemId} must still be returned.");
            $this->assertSame($text, $questions[$itemId]['question_text']);
        }
    }

    public function test_api_never_exposes_correct_answers(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $response = $this->getJson("/api/v1/baseline-assessments/{$assessment->id}")
            ->assertStatus(200);

        $payload = json_encode($response->json('data'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('correct_answer', $payload);
        $this->assertStringNotContainsString('scoring_rule', $payload);

        foreach ($response->json('data.questions') as $question) {
            $this->assertArrayNotHasKey('correct_answer', $question);
            $this->assertArrayNotHasKey('is_correct', $question);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Submission from the frozen snapshot
    |--------------------------------------------------------------------------
    */

    public function test_submission_succeeds_after_source_questions_are_deleted(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        // Answers are derived from the FROZEN snapshot before the bank is
        // wiped, then the bank is removed entirely.
        $responses = $assessment->questionSnapshots
            ->map(fn (BaselineQuestionSnapshot $s) => [
                'question_id' => $s->item_id,
                'answer' => (string) ($s->options[0] ?? 'A'),
            ])
            ->values()
            ->all();

        BaselineAssessmentItem::query()->delete();

        $this->fakeIntelligence($assessment);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $responses,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_submission_validates_against_frozen_options_after_source_edit(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        $snapshot = $assessment->questionSnapshots()
            ->where('item_id', 'sql-001')
            ->firstOrFail();

        $frozenFirstOption = (string) $snapshot->options[0];

        // The live options no longer contain the frozen answer.
        BaselineAssessmentItem::query()
            ->where('item_id', 'sql-001')
            ->update(['options' => json_encode(['X', 'Y', 'Z'])]);

        $responses = $assessment->questionSnapshots
            ->map(fn (BaselineQuestionSnapshot $s) => [
                // Send a value that is only valid under the FROZEN list.
                'question_id' => $s->item_id,
                'answer' => $s->item_id === 'sql-001'
                    ? $frozenFirstOption
                    : (string) ($s->options[0] ?? 'A'),
            ])
            ->values()
            ->all();

        $this->fakeIntelligence($assessment);

        // Accepted, because validation reads the frozen option list.
        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $responses,
        ])->assertStatus(200);
    }

    public function test_submission_rejects_an_answer_only_valid_in_the_edited_live_options(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql', 'python']);

        BaselineAssessmentItem::query()
            ->where('item_id', 'sql-001')
            ->update(['options' => json_encode(['X', 'Y', 'Z'])]);

        $responses = $assessment->questionSnapshots
            ->map(fn (BaselineQuestionSnapshot $s) => [
                'question_id' => $s->item_id,
                // 'X' exists only in the NEW live options — the frozen
                // snapshot never offered it, so it must be rejected.
                'answer' => $s->item_id === 'sql-001' ? 'X' : (string) ($s->options[0] ?? 'A'),
            ])
            ->values()
            ->all();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $responses,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_ANSWER_OPTION');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, string> column => ON DELETE action
     */
    private function foreignKeyActions(string $table): array
    {
        $actions = [];

        foreach (DB::select("PRAGMA foreign_key_list({$table})") as $fk) {
            $actions[$fk->from] = strtoupper((string) $fk->on_delete);
        }

        return $actions;
    }

    private function fakeIntelligence(BaselineAssessment $assessment): void
    {
        $assessment->loadMissing('questionSnapshots');

        $skills = $assessment->questionSnapshots
            ->map(fn (BaselineQuestionSnapshot $s) => $s->skill_id)
            ->unique()
            ->values();

        Http::fake([
            '*' => Http::response([
                'algorithm_version' => 'baseline-v1.0.0',
                'skills' => $skills->map(function ($skillId) {
                    $skill = Skill::findOrFail($skillId);

                    return [
                        'slug' => $skill->slug,
                        'skill_id' => (int) $skill->id,
                        'level' => 3.0,
                        'confidence' => 0.8,
                    ];
                })->all(),
            ], 200),
        ]);
    }

    private function startDynamicAssessment(User $user, array $skillSlugs): array
    {
        $role = $this->careerRole('Data Analyst', 'approved', $skillSlugs);
        $this->seedItems();

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        return [BaselineAssessment::with('questionSnapshots.item')->findOrFail($assessmentId), $role];
    }

    private function careerRole(string $title, string $status, array $skillSlugs): CareerRole
    {
        $role = CareerRole::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
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
                'importance_weight' => round(0.9 - ($index * 0.1), 3),
                'is_critical' => $index === 0,
            ]);
        }

        return $role;
    }

    private function seedItems(): void
    {
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $python = Skill::firstOrCreate(['slug' => 'python'], ['name' => 'Python', 'status' => 'active']);

        $this->item('sql-001', $sql->id, 'single_choice', ['A', 'B', 'C', 'D'], 'Which SQL clause filters rows?');
        $this->item('python-001', $python->id, 'single_choice', ['A', 'B', 'C', 'D'], 'What does len() return?');
    }

    private function item(
        string $itemId,
        int $skillId,
        string $type,
        array $options,
        ?string $questionText = null
    ): BaselineAssessmentItem {
        return BaselineAssessmentItem::updateOrCreate(
            ['assessment_version' => 'v1.0', 'item_id' => $itemId],
            [
                'item_type' => $type,
                'question_text' => $questionText,
                'skill_id' => $skillId,
                'options' => $options,
                'correct_answer' => $type === 'single_choice' ? $options[0] : null,
                'scoring_rule' => null,
                'weight' => 1.000,
                'is_active' => true,
            ],
        );
    }

    private function createLearner(): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
        ]);

        return [$user, $profile];
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
