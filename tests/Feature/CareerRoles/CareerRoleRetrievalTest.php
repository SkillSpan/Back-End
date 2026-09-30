<?php

namespace Tests\Feature\CareerRoles;

use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-INT-01 — the career role retrieval API
 * (GET /api/v1/career-roles, /{id}, /{id}/skills): learner-only
 * authorization, approved-only visibility, the paginator contract the
 * frontend is written against, and the decision-snapshot payload that
 * Readiness/Intelligence depend on.
 *
 * This suite is the regression net for the endpoints that feed the
 * intelligence flow; they had no coverage at all before it.
 */
class CareerRoleRetrievalTest extends TestCase
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

    // ------------------------------------------------ authorization

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->getJson('/api/v1/career-roles')->assertStatus(401);
        $this->getJson('/api/v1/career-roles/1')->assertStatus(401);
        $this->getJson('/api/v1/career-roles/1/skills')->assertStatus(401);
    }

    public function test_non_learner_cannot_list_career_roles(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $this->getJson('/api/v1/career-roles')
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_non_learner_cannot_read_a_single_career_role(): void
    {
        $role = $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $this->getJson('/api/v1/career-roles/'.$role->id)->assertStatus(403);
        $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')->assertStatus(403);
    }

    // ------------------------------------------------ index

    public function test_only_approved_roles_are_listed(): void
    {
        $this->approvedRole('Data Analyst');
        $this->role('Draft Role', 'draft');
        $this->role('Retired Role', 'retired');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles')->assertOk();

        $titles = array_column($response->json('data.data'), 'title');

        $this->assertSame(['Data Analyst'], $titles);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_index_keeps_the_paginator_inside_data(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career roles retrieved successfully.');

        // The frontend reads the items from `data.data`, not `data`.
        $this->assertIsArray($response->json('data.data'));
        $this->assertSame(1, $response->json('data.current_page'));
        $this->assertSame(15, $response->json('data.per_page'));
        $this->assertSame(1, $response->json('data.total'));

        // The paginator must not be nested under a `meta` key: that is
        // Pattern C of the frontend handoff.
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_index_includes_the_required_skill_count(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $this->attachSkill($role, 'SQL');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles')
            ->assertOk()
            ->assertJsonPath('data.data.0.skills_count', 1);
    }

    public function test_index_pagination_is_fixed_at_15_and_never_repeats_a_role(): void
    {
        // 18 approved roles: page 1 = 15, page 2 = 3.
        foreach (range(1, 18) as $index) {
            $this->approvedRole('Role '.$index);
        }

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $pageOne = $this->getJson('/api/v1/career-roles?page=1')->assertOk();
        $pageTwo = $this->getJson('/api/v1/career-roles?page=2')->assertOk();

        $this->assertCount(15, $pageOne->json('data.data'));
        $this->assertCount(3, $pageTwo->json('data.data'));
        $this->assertSame(18, $pageOne->json('data.total'));

        $idsPageOne = array_column($pageOne->json('data.data'), 'id');
        $idsPageTwo = array_column($pageTwo->json('data.data'), 'id');

        // No row may appear on both pages, and together they must cover
        // every approved role — the failure mode of an ordering without a
        // deterministic tie-breaker.
        $this->assertSame([], array_intersect($idsPageOne, $idsPageTwo));
        $this->assertCount(18, array_unique(array_merge($idsPageOne, $idsPageTwo)));

        // Ordering is version-descending with the id as the documented
        // tie-breaker, so the order is fully determined.
        $versions = array_column($pageOne->json('data.data'), 'version');
        $sorted = $versions;
        rsort($sorted);
        $this->assertSame($sorted, $versions);

        $sortedIds = $idsPageOne;
        rsort($sortedIds);
        $this->assertSame($sortedIds, $idsPageOne, 'equal versions must be ordered by id descending');
    }

    public function test_index_reports_the_request_id_in_the_body_and_the_header(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles', ['X-Request-ID' => 'career-roles-correlation'])
            ->assertOk();

        $this->assertSame('career-roles-correlation', $response->headers->get('X-Request-ID'));
        $this->assertSame('career-roles-correlation', $response->json('request_id'));
    }

    public function test_index_returns_an_empty_page_when_no_role_is_approved(): void
    {
        $this->role('Draft Role', 'draft');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles')->assertOk();

        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));
    }

    // ------------------------------------------------ show

    public function test_show_returns_the_approved_role(): void
    {
        $role = $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $role->id)
            ->assertJsonPath('data.title', 'Data Analyst')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame($role->effective_date->toDateString(), substr((string) $response->json('data.effective_date'), 0, 10));
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_show_returns_404_for_an_unknown_role(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles/99999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND')
            ->assertJsonPath('details.career_role_id', '99999');
    }

    public function test_show_returns_422_for_a_role_that_is_not_approved(): void
    {
        $role = $this->role('Draft Role', 'draft');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles/'.$role->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    // ------------------------------------------------ skills

    public function test_skills_returns_the_decision_snapshot_payload(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $sql = $this->attachSkill($role, 'SQL', requiredLevel: 4.0, weight: 0.45, critical: true);
        $this->attachSkill($role, 'Power BI', requiredLevel: 3.0, weight: 0.25, critical: false);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.career_role.id', $role->id)
            ->assertJsonPath('data.career_role.version', 1)
            ->assertJsonPath('data.statistics.total_skills', 2)
            ->assertJsonPath('data.statistics.critical_skills_count', 1)
            ->assertJsonPath('data.snapshot.is_valid', true);

        $skills = $response->json('data.skills');

        $this->assertCount(2, $skills);
        $this->assertSame(
            ['skill_id', 'skill_name', 'slug', 'required_level', 'importance_weight', 'is_critical', 'prerequisites'],
            array_keys($skills[0]),
        );
        $this->assertSame($sql->skill_id, $skills[0]['skill_id']);
        $this->assertSame('SQL', $skills[0]['skill_name']);
        $this->assertSame(4.0, (float) $skills[0]['required_level']);
        $this->assertSame(0.45, (float) $skills[0]['importance_weight']);
        $this->assertTrue($skills[0]['is_critical']);

        // Average importance weight is derived, never hardcoded.
        $this->assertSame(0.35, (float) $response->json('data.statistics.average_importance_weight'));
    }

    public function test_skills_includes_prerequisites_from_the_dependency_table(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $sql = $this->attachSkill($role, 'SQL', requiredLevel: 4.0, weight: 0.5, critical: true);
        $python = $this->attachSkill($role, 'Python', requiredLevel: 3.0, weight: 0.5, critical: false);

        // Python requires SQL — stored relationally, not as free text.
        $python->prerequisites()->attach($sql->skill_id);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')->assertOk();

        $skills = collect($response->json('data.skills'));
        $pythonSkill = $skills->firstWhere('skill_name', 'Python');

        $this->assertNotNull($pythonSkill);
        $this->assertCount(1, $pythonSkill['prerequisites']);
        $this->assertSame($sql->skill_id, $pythonSkill['prerequisites'][0]['skill_id']);
        $this->assertSame('SQL', $pythonSkill['prerequisites'][0]['name']);

        // A skill with no dependency must report an empty list, never null.
        $this->assertSame([], $skills->firstWhere('skill_name', 'SQL')['prerequisites']);
    }

    public function test_skills_returns_422_when_the_role_has_no_skills(): void
    {
        $role = $this->approvedRole('Empty Role');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NO_SKILLS');
    }

    public function test_skills_returns_404_for_an_unknown_role(): void
    {
        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles/99999/skills')
            ->assertStatus(404)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND');
    }

    public function test_skills_returns_422_for_a_role_that_is_not_approved(): void
    {
        $role = $this->role('Draft Role', 'draft');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    // ------------------------------------------------ helpers

    private function approvedRole(string $title, int $version = 1): CareerRole
    {
        return $this->role($title, 'approved', $version);
    }

    private function role(string $title, string $status, int $version = 1): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => $version,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function attachSkill(
        CareerRole $role,
        string $name,
        float $requiredLevel = 3.0,
        float $weight = 0.5,
        bool $critical = false,
    ): CareerRoleSkill {
        $skill = Skill::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        ]);

        return CareerRoleSkill::create([
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'required_level' => $requiredLevel,
            'importance_weight' => $weight,
            'is_critical' => $critical,
        ]);
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }
}
