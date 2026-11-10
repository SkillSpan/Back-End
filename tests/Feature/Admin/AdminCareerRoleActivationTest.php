<?php

namespace Tests\Feature\Admin;

use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCareerRoleActivationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    public function test_a_guest_cannot_use_the_activation_endpoints(): void
    {
        $role = $this->careerRole('Backend Developer');

        $this->postJson('/admin/api/career-roles/'.$role->id.'/deactivate')->assertStatus(401);
        $this->postJson('/admin/api/career-roles/'.$role->id.'/activate')->assertStatus(401);
        $this->getJson('/admin/api/career-roles')->assertStatus(401);
    }

    public function test_a_learner_cannot_use_the_activation_endpoints(): void
    {
        $role = $this->careerRole('Backend Developer');

        $this->actingAs($this->learner());

        $this->getJson('/admin/api/career-roles')->assertStatus(403);
        $this->postJson('/admin/api/career-roles/'.$role->id.'/deactivate')->assertStatus(403);
        $this->postJson('/admin/api/career-roles/'.$role->id.'/activate')->assertStatus(403);

        $this->assertTrue((bool) $role->fresh()->is_active, 'A learner must not be able to switch a role off.');
    }

    public function test_an_admin_can_list_career_roles_with_their_activation_state(): void
    {
        $this->actingAs($this->admin());

        $active = $this->careerRole('Backend Developer');
        $inactive = $this->careerRole('QA Engineer');
        $inactive->update(['is_active' => false]);

        $response = $this->getJson('/admin/api/career-roles')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career roles retrieved successfully.');

        $rows = collect($response->json('data.data'))->keyBy('slug');

        $this->assertTrue($rows[$active->slug]['is_active']);
        $this->assertFalse($rows[$inactive->slug]['is_active']);
        $this->assertSame('approved', $rows[$active->slug]['status']);
        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_the_list_can_be_filtered_by_activation_state(): void
    {
        $this->actingAs($this->admin());

        $active = $this->careerRole('Backend Developer');
        $inactive = $this->careerRole('QA Engineer');
        $inactive->update(['is_active' => false]);

        $onlyInactive = $this->getJson('/admin/api/career-roles?is_active=0')->assertOk();

        $this->assertSame(1, $onlyInactive->json('data.total'));
        $this->assertSame($inactive->slug, $onlyInactive->json('data.data.0.slug'));

        $onlyActive = $this->getJson('/admin/api/career-roles?is_active=1')->assertOk();

        $this->assertSame(1, $onlyActive->json('data.total'));
        $this->assertSame($active->slug, $onlyActive->json('data.data.0.slug'));
    }

    public function test_deactivating_a_role_removes_it_from_the_learner_list_without_deleting_anything(): void
    {
        $role = $this->careerRole('Backend Developer');
        $this->attachSkillWithQuestion($role, 'REST APIs');

        $specialization = Specialization::create(['name' => 'Software Engineering', 'is_active' => true]);
        $specialization->careerRoles()->attach($role->id);

        $skillIds = $role->roleSkills()->pluck('skill_id')->all();
        $questionCount = $this->questionsForRole($role);

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$role->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Career role deactivated.')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'approved');

        $this->assertFalse((bool) $role->fresh()->is_active);

        $this->assertSame($skillIds, $role->fresh()->roleSkills()->pluck('skill_id')->all());
        $this->assertSame($questionCount, $this->questionsForRole($role));
        $this->assertSame(1, $specialization->fresh()->careerRoles()->count());

        Sanctum::actingAs($this->learnerWithProfile());

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?per_page=100')->assertOk()->json('data.data'),
            'title',
        );

        $this->assertNotContains('Backend Developer', $titles);
    }

    public function test_deactivating_an_already_inactive_role_is_idempotent(): void
    {
        $role = $this->careerRole('QA Engineer');
        $role->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$role->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_admin_can_activate_an_approved_role_and_it_appears_for_learners(): void
    {
        $role = $this->careerRole('Backend Developer');
        $role->update(['is_active' => false]);
        $this->attachSkillWithQuestion($role, 'REST APIs');

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$role->id.'/activate')
            ->assertOk()
            ->assertJsonPath('message', 'Career role activated.')
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue((bool) $role->fresh()->is_active);

        Sanctum::actingAs($this->learnerWithProfile());

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?per_page=100')->assertOk()->json('data.data'),
            'title',
        );

        $this->assertContains('Backend Developer', $titles);
    }

    public function test_a_role_that_is_not_approved_cannot_be_activated(): void
    {
        $role = $this->careerRole('Draft Role', 'draft');
        $role->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$role->id.'/activate')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'CAREER_ROLE_ACTIVATION_REFUSED')
            ->assertJsonPath('details.status', 'draft');

        $this->assertFalse((bool) $role->fresh()->is_active);
        $this->assertSame('draft', $role->fresh()->status, 'Activation must never change the review status.');
    }

    public function test_a_retired_role_cannot_be_activated(): void
    {
        $role = $this->careerRole('Retired Role', 'retired');
        $role->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$role->id.'/activate')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CAREER_ROLE_ACTIVATION_REFUSED');
    }

    public function test_a_role_created_from_the_panel_is_inactive_and_hidden_from_learners(): void
    {
        $this->actingAs($this->admin());
        $skill = Skill::create(['name' => 'Solidity', 'slug' => 'solidity', 'status' => 'active']);

        $roleId = $this->postJson('/admin/api/specializations/career-roles', [
            'title' => 'Smart Contract Developer',
            'skill_ids' => [$skill->id],
        ])->assertStatus(201)->json('data.id');

        $role = CareerRole::findOrFail($roleId);

        $this->assertFalse((bool) $role->is_active, 'A new role must not be published automatically.');
        $this->assertSame('approved', $role->status);

        Sanctum::actingAs($this->learnerWithProfile());

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?per_page=100')->assertOk()->json('data.data'),
            'title',
        );

        $this->assertNotContains('Smart Contract Developer', $titles);

        $this->actingAs($this->admin())
            ->postJson('/admin/api/career-roles/'.$roleId.'/activate')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue((bool) $role->fresh()->is_active);
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => uniqid().'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->adminRole->id);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'learner@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function learnerWithProfile(): User
    {
        $user = $this->learner();

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    private function careerRole(string $title, string $status = 'approved'): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function attachSkillWithQuestion(CareerRole $role, string $skillName): BaselineAssessmentItem
    {
        $skill = Skill::create([
            'name' => $skillName,
            'slug' => strtolower(str_replace(' ', '-', $skillName)).'-'.uniqid(),
            'status' => 'active',
        ]);

        CareerRoleSkill::create([
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'required_level' => 3.0,
            'importance_weight' => 0.8,
            'is_critical' => true,
        ]);

        return BaselineAssessmentItem::create([
            'assessment_version' => 'v1.0',
            'item_id' => 'item-'.uniqid(),
            'item_type' => 'single_choice',
            'question_text' => "Prompt for {$skillName}?",
            'skill_id' => $skill->id,
            'options' => ['Option A', 'Option B'],
            'correct_answer' => 'Option A',
            'scoring_rule' => null,
            'weight' => 1.000,
            'is_active' => true,
        ]);
    }

    private function questionsForRole(CareerRole $role): int
    {
        $skillIds = $role->roleSkills()->pluck('skill_id')->all();

        if ($skillIds === []) {
            return 0;
        }

        return BaselineAssessmentItem::whereIn('skill_id', $skillIds)->count();
    }
}
