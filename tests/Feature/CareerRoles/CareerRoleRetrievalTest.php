<?php

namespace Tests\Feature\CareerRoles;

use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

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

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->getJson('/api/v1/career-roles')->assertStatus(401);
        $this->getJson('/api/v1/career-roles/1')->assertStatus(401);
        $this->getJson('/api/v1/career-roles/1/skills')->assertStatus(401);
    }

    public function test_a_company_admin_can_list_career_roles(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $this->getJson('/api/v1/career-roles')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_a_company_admin_can_read_a_single_career_role(): void
    {
        $role = $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $this->getJson('/api/v1/career-roles/'.$role->id)->assertStatus(200);
    }

    public function test_a_company_admin_can_read_the_role_skill_map(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $this->attachSkill($role, 'SQL', 3.0, 0.8, true);

        Sanctum::actingAs($this->createUserWithRole($this->companyRole));

        $response = $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertStatus(200)
            ->assertJsonPath('data.career_role.id', $role->id)
            ->assertJsonPath('data.skills.0.skill_name', 'SQL');

        $this->assertSame(
            ['skill_id', 'skill_name', 'slug', 'required_level', 'importance_weight', 'is_critical', 'prerequisites'],
            array_keys($response->json('data.skills.0')),
        );
    }

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

        $this->assertIsArray($response->json('data.data'));
        $this->assertSame(1, $response->json('data.current_page'));
        $this->assertSame(15, $response->json('data.per_page'));
        $this->assertSame(1, $response->json('data.total'));

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

    public function test_index_default_page_size_is_15_and_never_repeats_a_role(): void
    {
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

        $this->assertSame([], array_intersect($idsPageOne, $idsPageTwo));
        $this->assertCount(18, array_unique(array_merge($idsPageOne, $idsPageTwo)));

        $versions = array_column($pageOne->json('data.data'), 'version');
        $sorted = $versions;
        rsort($sorted);
        $this->assertSame($sorted, $versions);

        $sortedIds = $idsPageOne;
        rsort($sortedIds);
        $this->assertSame($sortedIds, $idsPageOne, 'equal versions must be ordered by id descending');
    }

    public function test_per_page_is_honoured(): void
    {
        foreach (range(1, 18) as $index) {
            $this->approvedRole('Role '.$index);
        }

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles?per_page=5')->assertOk();

        $this->assertCount(5, $response->json('data.data'));
        $this->assertSame(5, $response->json('data.per_page'));
        $this->assertSame(18, $response->json('data.total'));
        $this->assertSame(4, $response->json('data.last_page'));
    }

    public function test_per_page_50_is_honoured(): void
    {
        foreach (range(1, 55) as $index) {
            $this->approvedRole('Role '.$index);
        }

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles?per_page=50')->assertOk();

        $this->assertCount(50, $response->json('data.data'));
        $this->assertSame(50, $response->json('data.per_page'));
        $this->assertSame(55, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.last_page'));
    }

    public function test_per_page_is_clamped_to_the_maximum(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles?per_page=100000')->assertOk();

        $this->assertSame(100, $response->json('data.per_page'));
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_a_non_positive_per_page_falls_back_to_the_default(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        foreach (['0', '-5'] as $value) {
            $this->getJson('/api/v1/career-roles?per_page='.$value)
                ->assertOk()
                ->assertJsonPath('data.per_page', 15);
        }
    }

    public function test_a_non_numeric_per_page_falls_back_to_the_default(): void
    {
        $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $this->getJson('/api/v1/career-roles?per_page=abc')
            ->assertOk()
            ->assertJsonPath('data.per_page', 15);
    }

    public function test_per_page_does_not_change_the_response_shape(): void
    {
        foreach (range(1, 3) as $index) {
            $this->approvedRole('Role '.$index);
        }

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career roles retrieved successfully.');

        $this->assertIsArray($response->json('data.data'));
        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertSame(2, $response->json('data.per_page'));
        $this->assertSame(2, $response->json('data.last_page'));
    }

    public function test_per_page_combines_with_the_specialization_filter(): void
    {
        $specialization = Specialization::firstOrCreate(
            ['name' => 'Data Track'],
            ['is_active' => true],
        );

        foreach (range(1, 4) as $index) {
            $role = $this->approvedRole('Linked Role '.$index);
            $role->specializations()->attach($specialization->id);
        }

        $this->approvedRole('Unlinked Role');

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson(
            '/api/v1/career-roles?specialization_id='.$specialization->id.'&per_page=2'
        )->assertOk();

        $this->assertSame(5, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.per_page'));
        $this->assertCount(2, $response->json('data.data'));
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

        $this->assertSame(0.35, (float) $response->json('data.statistics.average_importance_weight'));
    }

    public function test_skills_includes_prerequisites_from_the_dependency_table(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $sql = $this->attachSkill($role, 'SQL', requiredLevel: 4.0, weight: 0.5, critical: true);
        $python = $this->attachSkill($role, 'Python', requiredLevel: 3.0, weight: 0.5, critical: false);

        $python->prerequisites()->attach($sql->skill_id);

        Sanctum::actingAs($this->createUserWithRole($this->learnerRole));

        $response = $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')->assertOk();

        $skills = collect($response->json('data.skills'));
        $pythonSkill = $skills->firstWhere('skill_name', 'Python');

        $this->assertNotNull($pythonSkill);
        $this->assertCount(1, $pythonSkill['prerequisites']);
        $this->assertSame($sql->skill_id, $pythonSkill['prerequisites'][0]['skill_id']);
        $this->assertSame('SQL', $pythonSkill['prerequisites'][0]['name']);

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
