<?php

namespace Tests\Feature\Talent;

use App\Models\CareerRole;
use App\Models\Evaluation;
use App\Models\EvaluationDimensionScore;
use App\Models\LearnerSkill;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\ReadinessResult;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Talent\TalentDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TalentDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function organization(string $name = 'Acme', string $status = 'verified'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function companyAdmin(?Organization $organization = null, string $organizationStatus = 'verified'): User
    {
        $organization ??= $this->organization('Acme', $organizationStatus);

        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('company-admin-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->companyAdminRole->id, ['organization_id' => $organization->id]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function student(
        string $name = 'Lena Kim',
        array $profile = [],
        ?Organization $organization = null,
        string $accountStatus = 'active',
    ): User {
        $user = User::forceCreate([
            'name' => $name,
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => $accountStatus,
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        StudentProfile::forceCreate(array_merge([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'visibility' => 'public',
            'consent_given' => true,
            'completeness_percent' => 80,
        ], $profile));

        if ($organization !== null) {
            OrganizationMember::forceCreate([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_in_org' => 'member',
                'status' => 'active',
            ]);
        }

        return $user;
    }

    private function readiness(User $student, float $score = 70.0, ?string $band = 'near_ready'): ReadinessResult
    {
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        $careerRole = $profile->primary_career_role_id === null
            ? CareerRole::forceCreate([
                'title' => 'Software Engineer',
                'slug' => 'software-engineer-'.uniqid(),
                'version' => 1,
                'status' => 'approved',
            ])
            : CareerRole::findOrFail($profile->primary_career_role_id);

        if ($profile->primary_career_role_id === null) {
            $profile->forceFill(['primary_career_role_id' => $careerRole->id])->save();
        }

        return ReadinessResult::forceCreate([
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'career_role_version' => 1,
            'score' => $score,
            'band' => $band,
            'critical_cap_applied' => false,
            'algorithm_version' => 'readiness-v1',
            'configuration_version' => 'config-v1',
            'calculated_at' => now(),
        ]);
    }

    private function skill(User $student, string $name, float $level, bool $verified = true): Skill
    {
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        $skill = Skill::forceCreate([
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
            'category' => 'Frontend',
        ]);

        LearnerSkill::forceCreate([
            'learner_id' => $student->id,
            'skill_id' => $skill->id,
            'level' => $level,
            'confidence_score' => 90,
        ]);

        SkillEvidence::forceCreate([
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
            'source' => 'project_performance',
            'value' => $level,
            'normalized_value' => 0.8,
            'evidence_date' => now()->toDateString(),
            'verification_status' => $verified ? 'verified' : 'pending',
        ]);

        return $skill;
    }

    private function finalizedEvaluation(User $student, User $evaluator, float $overall, float $dimensionScore): void
    {
        $project = Project::forceCreate([
            'owner_id' => $evaluator->id,
            'type' => 'simulation',
            'title' => 'E-Commerce Platform Rebuild',
        ]);

        $team = ProjectTeam::forceCreate(['project_id' => $project->id, 'name' => 'Team A']);

        ProjectTeamMember::forceCreate([
            'project_team_id' => $team->id,
            'user_id' => $student->id,
            'project_role' => 'Frontend Lead',
            'assignment_state' => 'completed',
        ]);

        $rubric = Rubric::forceCreate(['title' => 'Engineering Rubric', 'status' => 'published']);

        $dimension = RubricDimension::forceCreate([
            'rubric_id' => $rubric->id,
            'name' => 'quality',
            'weight' => 1,
        ]);

        $evaluation = Evaluation::forceCreate([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'rubric_id' => $rubric->id,
            'rubric_version' => 1,
            'status' => 'finalized',
            'overall_score' => $overall,
            'finalized_at' => now(),
        ]);

        EvaluationDimensionScore::forceCreate([
            'evaluation_id' => $evaluation->id,
            'rubric_dimension_id' => $dimension->id,
            'score' => $dimensionScore,
        ]);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/talent')->assertStatus(401);
    }

    public function test_a_learner_cannot_reach_talent_discovery(): void
    {
        $learner = $this->student('Curious Learner');

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/talent')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_a_learner_cannot_read_another_students_profile_through_the_detail_route(): void
    {
        $learner = $this->student('Curious Learner');
        $target = $this->student('Target Student');

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/talent/'.$target->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_a_company_admin_of_an_unapproved_organization_is_refused(): void
    {
        $admin = $this->companyAdmin(null, 'pending');
        $this->student('Visible Student');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_public_student_who_consented_is_discoverable(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Lena Kim');
        $this->readiness($student, 73.5, 'near_ready');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.name', 'Lena Kim')
            ->assertJsonPath('data.0.visibility', 'public')
            ->assertJsonPath('data.0.contactable', true)
            ->assertJsonPath('data.0.readiness.score', 73.5)
            ->assertJsonPath('data.0.readiness.band', 'near_ready')
            ->assertJsonPath('data.0.career_role.title', 'Software Engineer')
            ->assertJsonStructure([
                'success', 'message', 'data', 'request_id',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_a_stored_score_without_a_band_still_reports_the_band_it_falls_into(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Banded By Score');
        $this->readiness($student, 29.35, null);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('data.0.readiness.score', 29.35)
            ->assertJsonPath('data.0.readiness.band', 'foundation_needed');
    }

    public function test_each_band_boundary_is_derived_the_same_way_as_the_engine(): void
    {
        $admin = $this->companyAdmin();

        $cases = [
            [0.0, 'foundation_needed'],
            [39.99, 'foundation_needed'],
            [40.0, 'developing'],
            [59.99, 'developing'],
            [60.0, 'moderate_readiness'],
            [74.99, 'moderate_readiness'],
            [75.0, 'near_ready'],
            [89.99, 'near_ready'],
            [90.0, 'highly_ready'],
            [100.0, 'highly_ready'],
        ];

        foreach ($cases as $index => [$score, $expected]) {
            $student = $this->student('Boundary '.$index);
            $this->readiness($student, $score, null);
        }

        Sanctum::actingAs($admin);

        $rows = collect($this->getJson('/api/v1/talent?per_page=50')->json('data'))
            ->keyBy('name');

        foreach ($cases as $index => [$score, $expected]) {
            $this->assertSame(
                $expected,
                $rows['Boundary '.$index]['readiness']['band'],
                "score {$score} should band as {$expected}",
            );
        }
    }

    public function test_a_student_with_no_readiness_row_has_no_band(): void
    {
        $admin = $this->companyAdmin();
        $this->student('Unscored');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('data.0.readiness.score', null)
            ->assertJsonPath('data.0.readiness.band', null);
    }

    public function test_a_student_who_did_not_consent_is_absent(): void
    {
        $admin = $this->companyAdmin();
        $this->student('No Consent', ['consent_given' => false]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_a_private_student_is_absent(): void
    {
        $admin = $this->companyAdmin();
        $this->student('Private One', ['visibility' => 'private']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_an_organization_only_student_in_the_same_organization_is_discoverable(): void
    {
        $organization = $this->organization('Ours');
        $admin = $this->companyAdmin($organization);
        $this->student('Org Student', ['visibility' => 'organization_only'], $organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Org Student')
            ->assertJsonPath('data.0.visibility', 'organization_only');
    }

    public function test_an_organization_only_student_in_another_organization_is_absent(): void
    {
        $ours = $this->organization('Ours');
        $theirs = $this->organization('Theirs');

        $admin = $this->companyAdmin($ours);
        $this->student('Their Student', ['visibility' => 'organization_only'], $theirs);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_a_suspended_student_is_not_discoverable(): void
    {
        $admin = $this->companyAdmin();
        $this->student('Suspended One', [], null, 'suspended');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_list_is_paginated_without_overlapping_pages(): void
    {
        $admin = $this->companyAdmin();

        foreach (range(1, 5) as $index) {
            $this->student("Student {$index}");
        }

        Sanctum::actingAs($admin);

        $first = $this->getJson('/api/v1/talent?per_page=2&page=1');
        $first->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');

        $second = $this->getJson('/api/v1/talent?per_page=2&page=2');
        $second->assertOk()->assertJsonCount(2, 'data');

        $this->assertNotSame(
            $first->json('data.0.id'),
            $second->json('data.0.id'),
            'Page 2 repeated a row from page 1 — the sort is not deterministic.',
        );
    }

    public function test_a_per_page_above_the_cap_is_rejected(): void
    {
        $admin = $this->companyAdmin();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_the_search_filter_matches_the_name_and_the_career_role(): void
    {
        $admin = $this->companyAdmin();

        $this->student('Lena Kim');

        $marcus = $this->student('Marcus Chen');
        $this->readiness($marcus, 60.0);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?search=Lena')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Lena Kim');

        $this->getJson('/api/v1/talent?search=Software+Engineer')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Marcus Chen');
    }

    public function test_the_career_role_filter_matches_the_slug(): void
    {
        $admin = $this->companyAdmin();

        $matched = $this->student('Matched');
        $this->readiness($matched, 60.0);

        $this->student('Unmatched');

        $slug = StudentProfile::where('user_id', $matched->id)->firstOrFail()
            ->primaryCareerRole->slug;

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?career_role='.$slug)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Matched');
    }

    public function test_the_min_readiness_filter_excludes_students_below_the_threshold(): void
    {
        $admin = $this->companyAdmin();

        $high = $this->student('High Ready');
        $this->readiness($high, 82.0, 'highly_ready');

        $low = $this->student('Low Ready');
        $this->readiness($low, 40.0, 'developing');

        $this->student('No Readiness');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?min_readiness=70')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'High Ready');
    }

    public function test_the_readiness_sort_puts_the_highest_score_first(): void
    {
        $admin = $this->companyAdmin();

        $lower = $this->student('Lower');
        $this->readiness($lower, 40.0, 'developing');

        $higher = $this->student('Higher');
        $this->readiness($higher, 90.0, 'highly_ready');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?sort=readiness')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Higher')
            ->assertJsonPath('data.1.name', 'Lower');
    }

    public function test_an_unsupported_sort_is_rejected_rather_than_silently_defaulted(): void
    {
        $admin = $this->companyAdmin();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent?sort=match')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_the_detail_route_returns_the_skills_and_the_evaluation_summary(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Lena Kim', ['bio' => 'Frontend engineer']);

        $this->readiness($student, 73.5, 'near_ready');
        $this->skill($student, 'React', 3.5, verified: true);
        $this->finalizedEvaluation($student, $admin, 4.5, 4.6);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/'.$student->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.bio', 'Frontend engineer')
            ->assertJsonPath('data.readiness.score', 73.5)
            ->assertJsonPath('data.skills.0.name', 'React')
            ->assertJsonPath('data.skills.0.level', 3.5)
            ->assertJsonPath('data.skills.0.max_level', 5)
            ->assertJsonPath('data.skills.0.verified', true)
            ->assertJsonPath('data.skills.0.evidence_count', 1)
            ->assertJsonPath('data.projects.0.title', 'E-Commerce Platform Rebuild')
            ->assertJsonPath('data.projects.0.role', 'Frontend Lead')
            ->assertJsonPath('data.evaluation.authorized', true)
            ->assertJsonPath('data.evaluation.overall_rating', 4.5)
            ->assertJsonPath('data.evaluation.criteria_scores.0.key', 'quality')
            ->assertJsonPath('data.evaluation.criteria_scores.0.score', 4.6)
            ->assertJsonPath('data.evaluation.evaluations_count', 1);
    }

    public function test_the_detail_route_404s_for_a_student_the_caller_may_not_see(): void
    {
        $admin = $this->companyAdmin();
        $private = $this->student('Private One', ['visibility' => 'private']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/'.$private->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_the_detail_route_404s_for_a_student_who_did_not_consent(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('No Consent', ['consent_given' => false]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/'.$student->id)->assertStatus(404);
    }

    public function test_the_detail_route_404s_for_an_unknown_id(): void
    {
        $admin = $this->companyAdmin();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent/999999')->assertStatus(404);
    }

    public function test_a_student_who_is_not_visible_is_absent_from_the_list_as_well_as_the_detail_route(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Private One', ['visibility' => 'private']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/talent')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/talent/'.$student->id)->assertStatus(404);
    }

    public function test_the_email_address_is_never_exposed(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Lena Kim');

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/talent');
        $detail = $this->getJson('/api/v1/talent/'.$student->id);

        $this->assertArrayNotHasKey('email', $list->json('data.0'));
        $this->assertArrayNotHasKey('email', $detail->json('data'));
        $this->assertStringNotContainsString($student->email, $list->getContent());
        $this->assertStringNotContainsString($student->email, $detail->getContent());
    }

    public function test_the_evaluator_identity_is_not_exposed(): void
    {
        $admin = $this->companyAdmin();
        $student = $this->student('Lena Kim');
        $this->finalizedEvaluation($student, $admin, 4.5, 4.6);

        Sanctum::actingAs($admin);

        $evaluation = $this->getJson('/api/v1/talent/'.$student->id)->json('data.evaluation');

        $this->assertSame(
            ['authorized', 'overall_rating', 'criteria_scores', 'evaluations_count', 'reviewed_at'],
            array_keys($evaluation),
            'The evaluation block must not carry the evaluator identity or free-text notes.',
        );
    }

    public function test_recommendations_is_still_learner_only_and_unchanged(): void
    {
        $admin = $this->companyAdmin();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    private function careerRole(string $title, string $slug): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => $slug,
            'version' => 1,
            'status' => 'approved',
        ]);
    }

    private function assignCareerRole(User $student, CareerRole $role): void
    {
        StudentProfile::where('user_id', $student->id)
            ->firstOrFail()
            ->forceFill(['primary_career_role_id' => $role->id])
            ->save();
    }

    public function test_a_guest_cannot_read_the_filter_values(): void
    {
        $this->getJson('/api/v1/talent/filters')->assertStatus(401);
    }

    public function test_a_learner_cannot_read_the_filter_values(): void
    {
        Sanctum::actingAs($this->student('Lena Kim'));

        $this->getJson('/api/v1/talent/filters')->assertStatus(403);
    }

    public function test_the_filters_endpoint_lists_only_the_career_roles_that_can_return_a_result(): void
    {
        $admin = $this->companyAdmin();

        $visible = $this->careerRole('Backend Engineer', 'backend-engineer');
        $this->assignCareerRole($this->student('Visible Student'), $visible);

        $hidden = $this->careerRole('Data Scientist', 'data-scientist');
        $this->assignCareerRole(
            $this->student('Private Student', ['visibility' => 'private']),
            $hidden,
        );

        $this->careerRole('Unused Role', 'unused-role');

        Sanctum::actingAs($admin);

        $roles = $this->getJson('/api/v1/talent/filters')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.career_roles');

        $this->assertSame(
            ['backend-engineer'],
            array_column($roles, 'slug'),
            'Only roles that can actually yield a result for this caller may be listed.',
        );
    }

    public function test_the_filters_endpoint_does_not_leak_another_tenants_career_roles(): void
    {
        $myOrganization = $this->organization('Acme');
        $otherOrganization = $this->organization('Globex');

        $admin = $this->companyAdmin($myOrganization);

        $foreignRole = $this->careerRole('Embedded Engineer', 'embedded-engineer');
        $this->assignCareerRole(
            $this->student('Foreign Student', ['visibility' => 'organization_only'], $otherOrganization),
            $foreignRole,
        );

        Sanctum::actingAs($admin);

        $roles = $this->getJson('/api/v1/talent/filters')
            ->assertOk()
            ->json('data.career_roles');

        $this->assertSame(
            [],
            $roles,
            "A role held only by another organization's students must not be listed.",
        );
    }

    public function test_the_filters_endpoint_publishes_the_readiness_band_vocabulary(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $bands = $this->getJson('/api/v1/talent/filters')->json('data.readiness_bands');

        $this->assertSame(TalentDiscoveryService::READINESS_BANDS, $bands);
    }

    public function test_every_slug_the_filters_endpoint_returns_is_accepted_by_the_list(): void
    {
        $admin = $this->companyAdmin();

        $role = $this->careerRole('Backend Engineer', 'backend-engineer');
        $student = $this->student('Visible Student');
        $this->assignCareerRole($student, $role);

        Sanctum::actingAs($admin);

        $slug = $this->getJson('/api/v1/talent/filters')->json('data.career_roles.0.slug');

        $this->assertSame('backend-engineer', $slug);

        $this->getJson('/api/v1/talent?career_role='.$slug)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Visible Student');
    }
}
