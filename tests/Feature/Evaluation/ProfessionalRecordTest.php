<?php

namespace Tests\Feature\Evaluation;

use App\Events\EvaluationFinalized;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\ProfessionalRecordItem;
use App\Models\ProfessionalRecordSectionVisibility;
use App\Models\ProfessionalRecordShareAccess;
use App\Models\ProfessionalRecordShareLink;
use App\Models\Project;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessionalRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private StudentProfile $profile;

    private Project $project;

    private Rubric $rubric;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Record Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');

        $this->profile = StudentProfile::forceCreate([
            'user_id' => $this->member->id,
            'specialization' => 'software_engineering',
            'career_status' => 'student',
        ]);

        $this->rubric = Rubric::create([
            'title' => 'Record Rubric',
            'version' => 1,
            'status' => Rubric::STATUS_PUBLISHED,
            'score_min' => 0,
            'score_max' => 100,
            'published_at' => now(),
        ]);

        $this->project = Project::forceCreate([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'type' => 'company_sponsored',
            'title' => 'Record Project',
            'status' => Project::STATUS_COMPLETED,
            'confidentiality' => 'public',
            'rubric_id' => $this->rubric->id,
        ]);

        ProfessionalRecordItem::create([
            'student_profile_id' => $this->profile->id,
            'source_type' => 'evaluation',
            'source_id' => 1,
            'item_type' => ProfessionalRecordItem::TYPE_EVALUATION,
            'title' => 'Record Project',
            'description' => 'Delivered the agreed scope.',
            'role' => 'participant',
            'issuer' => 'Record Org',
            'verification_state' => ProfessionalRecordItem::VERIFICATION_VERIFIED,
            'status' => ProfessionalRecordItem::STATUS_ACTIVE,
            'idempotency_key' => 'evaluation:1',
            'visibility' => ProfessionalRecordItem::VISIBILITY_PRIVATE,
            'achieved_at' => now()->toDateString(),
        ]);

        ProfessionalRecordItem::create([
            'student_profile_id' => $this->profile->id,
            'source_type' => 'skill',
            'source_id' => 2,
            'item_type' => ProfessionalRecordItem::TYPE_SKILL,
            'title' => 'Code Quality',
            'description' => 'Verified through project evaluation.',
            'issuer' => 'SkillSpan',
            'verification_state' => ProfessionalRecordItem::VERIFICATION_VERIFIED,
            'status' => ProfessionalRecordItem::STATUS_ACTIVE,
            'idempotency_key' => 'skill:1',
            'visibility' => ProfessionalRecordItem::VISIBILITY_PRIVATE,
            'achieved_at' => now()->toDateString(),
        ]);
    }

    private function user(string $email, string $role, ?Organization $organization = null): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        if ($organization !== null) {
            $user->organizations()->attach($organization->id, ['role_in_org' => 'admin', 'status' => 'active']);
        }

        return $user->fresh();
    }

    public function test_student_record_lists_sections_with_default_private_visibility(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/learner/professional-record')
            ->assertStatus(200)
            ->assertJsonPath('data.record.sections.evaluation.count', 1)
            ->assertJsonPath('data.record.sections.skill.count', 1)
            ->assertJsonPath('data.record.sections.evaluation.visibility', ProfessionalRecordItem::VISIBILITY_PRIVATE)
            ->assertJsonPath('data.record.total_items', 3)
            ->assertJsonPath('data.record.verified_items', 2)
            ->assertJsonPath('data.record.sections.profile.count', 1);
    }

    public function test_visibility_can_be_changed_per_section(): void
    {
        Sanctum::actingAs($this->member);

        $this->putJson('/api/v1/learner/professional-record/visibility', [
            'sections' => [
                'evaluation' => ProfessionalRecordItem::VISIBILITY_PUBLIC,
                'skill' => ProfessionalRecordItem::VISIBILITY_ORGANIZATION_ONLY,
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.visibility.evaluation', ProfessionalRecordItem::VISIBILITY_PUBLIC)
            ->assertJsonPath('data.visibility.skill', ProfessionalRecordItem::VISIBILITY_ORGANIZATION_ONLY);

        $this->assertSame(
            ProfessionalRecordItem::VISIBILITY_PUBLIC,
            ProfessionalRecordSectionVisibility::where('student_profile_id', $this->profile->id)->where('section', 'evaluation')->value('visibility'),
        );
    }

    public function test_an_unknown_section_is_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $this->putJson('/api/v1/learner/professional-record/visibility', [
            'sections' => ['certificates' => ProfessionalRecordItem::VISIBILITY_PUBLIC],
        ])->assertStatus(422);
    }

    public function test_a_share_link_returns_only_the_selected_sections(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/learner/professional-record/share-links', [
            'sections' => [ProfessionalRecordItem::TYPE_SKILL],
            'days' => 7,
        ])->assertStatus(201);

        $token = $response->json('data.token');

        $this->assertNotEmpty($token);
        $this->assertSame(1, ProfessionalRecordShareLink::where('student_profile_id', $this->profile->id)->count());

        $this->getJson("/api/v1/r/{$token}")
            ->assertStatus(200)
            ->assertJsonPath('data.record.sections.0', ProfessionalRecordItem::TYPE_SKILL)
            ->assertJsonCount(1, 'data.record.items')
            ->assertJsonPath('data.record.items.0.title', 'Code Quality');

        $this->assertSame(1, ProfessionalRecordShareAccess::count());
        $this->assertSame(1, ProfessionalRecordShareLink::first()->access_count);
    }

    public function test_a_revoked_share_link_is_unavailable(): void
    {
        Sanctum::actingAs($this->member);

        $token = $this->postJson('/api/v1/learner/professional-record/share-links', [
            'sections' => [ProfessionalRecordItem::TYPE_SKILL],
        ])->json('data.token');

        $link = ProfessionalRecordShareLink::first();

        $this->deleteJson("/api/v1/learner/professional-record/share-links/{$link->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.share_link.is_active', false);

        $this->getJson("/api/v1/r/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'RECORD_SHARE_UNAVAILABLE');
    }

    public function test_an_expired_share_link_is_unavailable(): void
    {
        Sanctum::actingAs($this->member);

        $token = $this->postJson('/api/v1/learner/professional-record/share-links', [
            'sections' => [ProfessionalRecordItem::TYPE_SKILL],
        ])->json('data.token');

        ProfessionalRecordShareLink::query()->update(['expires_at' => now()->subDay()]);

        $this->getJson("/api/v1/r/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'RECORD_SHARE_UNAVAILABLE');
    }

    public function test_a_student_cannot_revoke_someone_elses_share_link(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson('/api/v1/learner/professional-record/share-links', [
            'sections' => [ProfessionalRecordItem::TYPE_SKILL],
        ])->assertStatus(201);

        $link = ProfessionalRecordShareLink::first();
        $stranger = $this->user('stranger@test.com', 'learner');
        StudentProfile::forceCreate([
            'user_id' => $stranger->id,
            'specialization' => 'software_engineering',
            'career_status' => 'student',
        ]);

        Sanctum::actingAs($stranger);

        $this->deleteJson("/api/v1/learner/professional-record/share-links/{$link->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'RECORD_SHARE_FORBIDDEN');
    }

    public function test_a_verification_reference_resolves_to_the_record_item(): void
    {
        Sanctum::actingAs($this->member);

        $item = ProfessionalRecordItem::where('item_type', ProfessionalRecordItem::TYPE_EVALUATION)->first();

        $reference = $this->postJson("/api/v1/learner/professional-record/items/{$item->id}/verification")
            ->assertStatus(201)
            ->json('data.verification.reference');

        $this->getJson("/api/v1/verify/{$reference}")
            ->assertStatus(200)
            ->assertJsonPath('data.verification.verified', true)
            ->assertJsonPath('data.verification.item.title', 'Record Project')
            ->assertJsonPath('data.verification.student.name', 'User member@test.com');
    }

    public function test_an_unknown_verification_reference_returns_404(): void
    {
        $this->getJson('/api/v1/verify/SP-NOTAREALREFERENCE')
            ->assertStatus(404)
            ->assertJsonPath('code', 'RECORD_VERIFICATION_NOT_FOUND');
    }

    public function test_the_record_can_be_exported_as_a_printable_document(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->get('/api/v1/learner/professional-record/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Record Project', (string) $response->getContent());
        $this->assertStringContainsString('Code Quality', (string) $response->getContent());
    }

    public function test_the_record_export_can_be_limited_to_selected_sections_as_json(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/learner/professional-record/export?format=json&sections=skill')
            ->assertStatus(200)
            ->assertJsonPath('data.export.sections.0', ProfessionalRecordItem::TYPE_SKILL)
            ->assertJsonCount(1, 'data.export.items')
            ->assertJsonPath('data.export.items.0.title', 'Code Quality');
    }

    public function test_the_record_export_rejects_an_unknown_section(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/learner/professional-record/export?format=json&sections=certificates')
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECORD_SECTION_INVALID');
    }

    public function test_finalizing_an_evaluation_creates_a_record_item(): void
    {
        $evaluation = Evaluation::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'evaluator_id' => $this->project->owner_id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'rubric_version' => 1,
            'status' => Evaluation::STATUS_FINALIZED,
            'overall_score' => 82.0,
            'team_outcome' => 'Delivered everything.',
            'individual_contribution' => 'Owned the API.',
            'finalized_at' => now(),
        ]);

        EvaluationFinalized::dispatch($evaluation);

        $item = ProfessionalRecordItem::where('idempotency_key', 'evaluation:'.$evaluation->id)->first();

        $this->assertNotNull($item);
        $this->assertSame(ProfessionalRecordItem::TYPE_EVALUATION, $item->item_type);
        $this->assertSame(ProfessionalRecordItem::STATUS_ACTIVE, $item->status);
        $this->assertSame(ProfessionalRecordItem::VERIFICATION_VERIFIED, $item->verification_state);
        $this->assertSame('Record Org', $item->issuer);
    }
}
