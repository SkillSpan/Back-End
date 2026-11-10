<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantInteraction;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantReportTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->putJson('/api/v1/assistant/interactions/1/report', [
            'report_status' => AssistantInteraction::REPORT_UNSAFE,
        ])->assertStatus(401);
    }

    public function test_non_learner_cannot_report(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/assistant/interactions/1/report', [
            'report_status' => AssistantInteraction::REPORT_UNSAFE,
        ])->assertStatus(403);
    }

    public function test_a_learner_can_report_their_own_interaction(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $interaction = $this->createInteraction($profile);

        $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
            'report_status' => AssistantInteraction::REPORT_UNFAIR,
            'report_reason' => 'It assumed I had no experience.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.report_status', AssistantInteraction::REPORT_UNFAIR)
            ->assertJsonPath('data.report_reason', 'It assumed I had no experience.');

        $interaction->refresh();

        $this->assertSame(AssistantInteraction::REPORT_UNFAIR, $interaction->report_status);
        $this->assertNotNull($interaction->reported_at);
    }

    public function test_every_documented_classification_is_accepted(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        foreach (['unsafe', 'irrelevant', 'unfair', 'incorrect'] as $status) {
            $interaction = $this->createInteraction($profile);

            $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
                'report_status' => $status,
            ])
                ->assertStatus(200)
                ->assertJsonPath('data.report_status', $status);
        }
    }

    public function test_an_unknown_classification_is_rejected(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $interaction = $this->createInteraction($profile);

        $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
            'report_status' => 'dislike',
        ])->assertStatus(422);
    }

    public function test_a_learner_cannot_report_another_learners_interaction(): void
    {
        [$owner, $ownerProfile] = $this->createLearner();

        [$other] = $this->createLearner();

        Sanctum::actingAs($other);

        $interaction = $this->createInteraction($ownerProfile);

        $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
            'report_status' => AssistantInteraction::REPORT_UNSAFE,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ASSISTANT_INTERACTION_NOT_FOUND');

        $this->assertNull($interaction->refresh()->report_status);
        $this->assertNotSame($owner->id, $other->id);
    }

    public function test_a_non_existent_interaction_is_indistinguishable_from_a_forbidden_one(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/assistant/interactions/999999/report', [
            'report_status' => AssistantInteraction::REPORT_UNSAFE,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ASSISTANT_INTERACTION_NOT_FOUND');
    }

    public function test_reporting_twice_overwrites_with_the_latest_classification(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $interaction = $this->createInteraction($profile);

        $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
            'report_status' => AssistantInteraction::REPORT_IRRELEVANT,
        ])->assertStatus(200);

        $this->putJson("/api/v1/assistant/interactions/{$interaction->id}/report", [
            'report_status' => AssistantInteraction::REPORT_INCORRECT,
            'report_reason' => 'The gap it named is not the largest one.',
        ])->assertStatus(200);

        $interaction->refresh();

        $this->assertSame(AssistantInteraction::REPORT_INCORRECT, $interaction->report_status);
        $this->assertSame('The gap it named is not the largest one.', $interaction->report_reason);
    }

    private function createInteraction(StudentProfile $profile): AssistantInteraction
    {
        return AssistantInteraction::create([
            'student_profile_id' => $profile->id,
            'intent' => 'explain_readiness',
            'context_reference' => 'ctx-test',
            'response_status' => AssistantInteraction::STATUS_SUCCEEDED,
            'configuration_version' => 'config-v1',
            'request_id' => (string) Str::uuid(),
        ]);
    }

    private function createLearner(): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
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
