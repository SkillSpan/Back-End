<?php

namespace Tests\Feature\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Services\Intelligence\IntelligenceClient;
use App\Services\Intelligence\IntelligencePayloadBuilder;
use App\Services\Intelligence\IntelligenceResponseValidator;
use Tests\TestCase;

class AssessmentAwareRoadmapTest extends TestCase
{
    private function payload(string $date, string $status = 'verified'): array
    {
        $profile = new StudentProfile;
        $profile->forceFill(['id' => 13, 'user_id' => 30]);
        $role = new CareerRole;
        $role->forceFill(['id' => 3, 'title' => 'Data Analyst', 'version' => 1]);
        $skill = new Skill;
        $skill->forceFill(['id' => 20, 'name' => 'Statistics Fundamentals']);
        $roleSkill = new CareerRoleSkill(['skill_id' => 20, 'required_level' => 3,
            'importance_weight' => .9, 'is_critical' => true]);
        $roleSkill->setRelation('skill', $skill);
        $roleSkill->setRelation('prerequisites', collect());
        $evaluation = new SkillEvaluation;
        $evaluation->forceFill(['id' => 1, 'skill_id' => 20, 'level' => 1, 'confidence' => 30,
            'calculated_at' => now(), 'algorithm_version' => 'skill-confidence-v2-baseline',
            'snapshot' => ['sources' => ['assessment_test' => ['records' => [[
                'verification_status' => $status, 'evidence_date' => $date,
            ]]]]]]);

        return app(IntelligencePayloadBuilder::class)->build($profile, $role,
            collect([$roleSkill]), collect([$evaluation]), [], 'readiness-v2-srs13', 'config-v1');
    }

    public function test_recent_verified_source_is_carried_in_the_authoritative_snapshot(): void
    {
        $payload = $this->payload(now()->toDateString());
        $this->assertTrue($payload['skills'][0]['has_recent_verified_assessment']);
        $this->assertEquals(30, $payload['skills'][0]['confidence']);
    }

    public function test_stale_pending_revoked_invalid_and_future_dates_cannot_establish_recency(): void
    {
        foreach ([
            [now()->subMonthsNoOverflow(7)->toDateString(), 'verified'],
            [now()->toDateString(), 'pending'],
            [now()->toDateString(), 'rejected'],
            ['not-a-date', 'verified'],
            [now()->addDay()->toDateString(), 'verified'],
        ] as [$date, $status]) {
            $this->assertFalse($this->payload($date, $status)['skills'][0]['has_recent_verified_assessment']);
        }
    }

    public function test_client_only_enables_policy_from_the_frozen_request(): void
    {
        $payload = $this->payload(now()->toDateString());
        $client = app(IntelligenceClient::class);
        $legacy = $client->toRoadmapRequest($payload);
        $this->assertArrayNotHasKey('action_selection_policy', $legacy);
        $this->assertArrayNotHasKey('has_recent_verified_assessment', $legacy['skills'][0]);
        $payload['roadmap_action_policy'] = 'assessment-aware-v2';
        $request = $client->toRoadmapRequest($payload);
        $this->assertSame('assessment-aware-v2', $request['action_selection_policy']);
        $this->assertTrue($request['skills'][0]['has_recent_verified_assessment']);
    }

    public function test_legacy_service_response_is_rejected_when_v2_was_requested(): void
    {
        $this->expectException(IntelligenceException::class);
        $this->expectExceptionMessage('has not applied the requested roadmap policy');
        app(IntelligenceResponseValidator::class)->validateRoadmap(
            ['algorithm_version' => 'roadmap-v1', 'configuration_version' => 'roadmap-config-v1'],
            ['roadmap_action_policy' => 'assessment-aware-v2'],
        );
    }
}
