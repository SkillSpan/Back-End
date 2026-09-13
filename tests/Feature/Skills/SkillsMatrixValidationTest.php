<?php

namespace Tests\Feature\Skills;

use App\Models\LearnerSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for two write-path defects on /api/v1/skills/matrix:
 *
 *  1. `calculated_at` was read with $request->only(), which pulls from the
 *     raw input bag rather than validated data, and had no validation rule.
 *     A client could therefore forge the provenance timestamp of its own
 *     (or, before the authorization fix, anyone's) skill record.
 *
 *  2. `source_contributions` was validated as a JSON *string* while the
 *     model casts the column to `array`. Eloquent encodes on write, so a
 *     pre-encoded string was encoded a second time and read back as a
 *     string instead of the object callers expect.
 */
class SkillsMatrixValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => null]);

        return $user;
    }

    private function skill(): Skill
    {
        return Skill::forceCreate([
            'name' => 'JavaScript',
            'slug' => 'javascript',
            'status' => 'active',
            'version' => 1,
        ]);
    }

    public function test_client_supplied_calculated_at_is_ignored_on_update(): void
    {
        $me = $this->learner();
        $skill = $this->skill();

        $row = LearnerSkill::forceCreate([
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 20,
        ]);

        Sanctum::actingAs($me);

        $this->putJson('/api/v1/skills/matrix/'.$row->id, [
            'level' => 4.0,
            'calculated_at' => '2000-01-01T00:00:00Z',
        ])->assertStatus(200);

        $row->refresh();

        // The server timestamp must win, not the client's forged one.
        $this->assertTrue(
            $row->calculated_at->greaterThan(now()->subMinutes(5)),
            'calculated_at should be server time, but was '.$row->calculated_at
        );
        $this->assertNotSame('2000', $row->calculated_at->format('Y'));
    }

    public function test_client_supplied_calculated_at_is_ignored_on_create(): void
    {
        $me = $this->learner();
        $skill = $this->skill();

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 3.0,
            'confidence_score' => 50,
            'calculated_at' => '2000-01-01T00:00:00Z',
        ])->assertStatus(201);

        $row = LearnerSkill::where('learner_id', $me->id)->first();

        $this->assertNotSame('2000', $row->calculated_at->format('Y'));
        $this->assertTrue($row->calculated_at->greaterThan(now()->subMinutes(5)));
    }

    public function test_source_contributions_round_trips_as_an_array(): void
    {
        $me = $this->learner();
        $skill = $this->skill();

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 3.0,
            'confidence_score' => 50,
            'source_contributions' => [
                'certificate' => 0.05,
                'project_performance' => 0.35,
            ],
        ])->assertStatus(201);

        $row = LearnerSkill::where('learner_id', $me->id)->first();

        // The cast must hand back the structured array, not a string.
        $this->assertIsArray($row->source_contributions);
        $this->assertSame(0.05, $row->source_contributions['certificate']);
        $this->assertSame(0.35, $row->source_contributions['project_performance']);

        // And the raw column must be a single JSON object — a double-encoded
        // value would decode to a string and start with a quote.
        $raw = DB::table('learner_skills')->where('id', $row->id)->value('source_contributions');
        $this->assertIsArray(json_decode($raw, true));
        $this->assertStringStartsWith('{', trim($raw));
    }

    public function test_source_contributions_round_trips_as_an_array_on_update(): void
    {
        $me = $this->learner();
        $skill = $this->skill();

        $row = LearnerSkill::forceCreate([
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 2.0,
            'confidence_score' => 20,
        ]);

        Sanctum::actingAs($me);

        $this->putJson('/api/v1/skills/matrix/'.$row->id, [
            'source_contributions' => ['expert_evaluation' => 0.2],
        ])->assertStatus(200);

        $row->refresh();

        $this->assertIsArray($row->source_contributions);
        $this->assertSame(0.2, $row->source_contributions['expert_evaluation']);
    }

    public function test_source_contributions_must_be_structured_not_a_json_string(): void
    {
        $me = $this->learner();
        $skill = $this->skill();

        Sanctum::actingAs($me);

        // A raw JSON string is now a validation error rather than a value
        // that gets silently double-encoded.
        $this->postJson('/api/v1/skills/matrix', [
            'learner_id' => $me->id,
            'skill_id' => $skill->id,
            'level' => 3.0,
            'confidence_score' => 50,
            'source_contributions' => '{"certificate":0.05}',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['source_contributions']);
    }
}
