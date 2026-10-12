<?php

namespace Tests\Feature\Evidence;

use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The evidence list endpoint: GET /api/v1/evidence.
 *
 * It used to hardcode `->where('verification_status', 'verified')`, so a
 * learner only ever saw approved evidence. That hid the two states a learner
 * most needs to act on — a reviewer's `revision` request and a `rejected`
 * decision — which made the revision workflow invisible to the person it
 * exists for. The list now returns every state, with an optional `status`
 * filter.
 *
 * These tests pin the new behaviour, the ownership boundary, the response
 * envelope, and the fact that no existing field was dropped.
 */
class EvidenceListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user;
    }

    /**
     * A learner with a profile and one evidence row per requested status.
     *
     * @param  array<int, string>  $statuses
     * @return array{0: User, 1: array<string, SkillEvidence>}
     */
    private function learnerWithEvidence(array $statuses): array
    {
        $learner = $this->user('learner');
        $profile = StudentProfile::forceCreate(['user_id' => $learner->id]);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql-'.uniqid()]);

        $records = [];

        foreach ($statuses as $status) {
            $records[$status] = SkillEvidence::create([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'source' => 'certificate',
                'value' => 0,
                'normalized_value' => 0,
                'reference' => 'https://example.com/'.$status.'.pdf',
                'evidence_date' => now(),
                'verification_status' => $status,
            ]);
        }

        return [$learner, $records];
    }

    // -----------------------------------------------------------------
    // 1. Every status is returned by default.
    // -----------------------------------------------------------------

    public function test_the_list_returns_evidence_in_every_status(): void
    {
        [$learner, $records] = $this->learnerWithEvidence([
            'pending', 'verified', 'rejected', 'expired', 'revision',
        ]);

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/evidence')->assertOk();

        $statuses = array_column($response->json('data'), 'verification_status');
        sort($statuses);

        $this->assertSame(
            ['expired', 'pending', 'rejected', 'revision', 'verified'],
            $statuses,
        );

        // And every row is one of the learner's own records.
        $returnedIds = array_column($response->json('data'), 'id');
        sort($returnedIds);
        $expectedIds = array_map(fn (SkillEvidence $e) => $e->id, array_values($records));
        sort($expectedIds);

        $this->assertSame($expectedIds, $returnedIds);
    }

    /**
     * One test per status, so a future filter regression names the exact
     * state it broke.
     *
     * @dataProvider statusProvider
     */
    public function test_a_learner_can_list_their_evidence_in_each_status(string $status): void
    {
        [$learner, $records] = $this->learnerWithEvidence([$status]);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/evidence')
            ->assertOk()
            ->assertJsonPath('data.0.id', $records[$status]->id)
            ->assertJsonPath('data.0.verification_status', $status);
    }

    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'verified' => ['verified'],
            'rejected' => ['rejected'],
            'expired' => ['expired'],
            'revision' => ['revision'],
        ];
    }

    /**
     * The whole point of the follow-up: a reviewer's clarification request
     * must be visible to the learner in the list, and must survive a reload.
     */
    public function test_revision_evidence_survives_reload_and_appears_in_the_list(): void
    {
        [$learner, $records] = $this->learnerWithEvidence(['revision']);

        Sanctum::actingAs($learner);

        // A completely fresh request.
        $this->getJson('/api/v1/evidence')
            ->assertOk()
            ->assertJsonPath('data.0.verification_status', 'revision')
            ->assertJsonPath('data.0.id', $records['revision']->id);

        // The row is still `revision` in the database.
        $this->assertSame('revision', $records['revision']->fresh()->verification_status);
    }

    // -----------------------------------------------------------------
    // 2. The optional `status` filter.
    // -----------------------------------------------------------------

    public function test_the_status_filter_returns_only_that_status(): void
    {
        [$learner] = $this->learnerWithEvidence([
            'pending', 'verified', 'rejected', 'expired', 'revision',
        ]);

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/evidence?status=revision')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('revision', $response->json('data.0.verification_status'));
    }

    public function test_status_verified_returns_only_verified_evidence(): void
    {
        [$learner] = $this->learnerWithEvidence(['pending', 'verified', 'revision']);

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/evidence?status=verified')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('verified', $response->json('data.0.verification_status'));
    }

    /**
     * Every documented state is a valid filter value — driven off the model
     * constants so a new state cannot be added without this staying honest.
     */
    public function test_every_documented_status_is_accepted_as_a_filter(): void
    {
        [$learner] = $this->learnerWithEvidence(['pending']);

        Sanctum::actingAs($learner);

        foreach (SkillEvidence::STATUSES as $status) {
            $this->getJson('/api/v1/evidence?status='.$status)->assertOk();
        }
    }

    public function test_an_invalid_status_is_rejected_with_422(): void
    {
        [$learner] = $this->learnerWithEvidence(['pending']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/evidence?status=approved')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_an_empty_status_is_treated_as_no_filter(): void
    {
        [$learner] = $this->learnerWithEvidence(['pending', 'verified']);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/evidence?status=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // -----------------------------------------------------------------
    // 3. Ownership.
    // -----------------------------------------------------------------

    public function test_a_learner_cannot_see_another_learners_evidence(): void
    {
        [$owner, $records] = $this->learnerWithEvidence(['pending', 'revision']);

        $other = $this->user('learner');
        StudentProfile::forceCreate(['user_id' => $other->id]);

        Sanctum::actingAs($other);

        $this->getJson('/api/v1/evidence')->assertOk()->assertJsonCount(0, 'data');

        // Even a filter that matches the owner's rows returns nothing.
        $filtered = $this->getJson('/api/v1/evidence?status=revision')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $filtered);
        $this->assertNotContains(
            $records['revision']->id,
            array_column($filtered, 'id'),
        );
    }

    public function test_the_list_only_returns_the_callers_own_rows_when_both_have_evidence(): void
    {
        [$owner, $ownerRecords] = $this->learnerWithEvidence(['pending']);

        [$other, $otherRecords] = $this->learnerWithEvidence(['verified']);

        Sanctum::actingAs($other);

        $response = $this->getJson('/api/v1/evidence')->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($otherRecords['verified']->id, $ids);
        $this->assertNotContains($ownerRecords['pending']->id, $ids);
    }

    // -----------------------------------------------------------------
    // 4. Contract preservation.
    // -----------------------------------------------------------------

    public function test_the_response_envelope_is_unchanged(): void
    {
        [$learner] = $this->learnerWithEvidence(['pending']);

        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/evidence')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Evidence retrieved successfully.');

        // The payload is a plain array under `data` — not a paginator, and
        // not wrapped in a `meta` key.
        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_the_existing_fields_are_still_returned(): void
    {
        [$learner, $records] = $this->learnerWithEvidence(['verified']);

        Sanctum::actingAs($learner);

        $item = $this->getJson('/api/v1/evidence')->assertOk()->json('data.0');

        foreach ([
            'id', 'student_profile_id', 'skill_id', 'source', 'value',
            'normalized_value', 'reference', 'description', 'evidence_date',
            'verification_status', 'reviewer_id', 'reviewer_notes',
            'recency_factor', 'skill', 'reviewer',
        ] as $field) {
            $this->assertArrayHasKey($field, $item, "missing field: {$field}");
        }

        $this->assertSame($records['verified']->id, $item['id']);
    }

    // -----------------------------------------------------------------
    // 5. Authorization (unchanged).
    // -----------------------------------------------------------------

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/evidence')->assertStatus(401);
    }

    public function test_a_non_learner_cannot_list_evidence(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/v1/evidence')->assertStatus(403);
    }

    public function test_a_learner_without_a_student_profile_is_rejected(): void
    {
        Sanctum::actingAs($this->user('learner'));

        $this->getJson('/api/v1/evidence')->assertStatus(422);
    }
}
