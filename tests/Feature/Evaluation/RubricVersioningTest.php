<?php

namespace Tests\Feature\Evaluation;

use App\Models\Role;
use App\Models\Rubric;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RubricVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Skill $qualitySkill;

    private Skill $deliverySkill;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $this->admin = $this->user('admin@test.com', 'admin');

        $this->qualitySkill = Skill::create(['name' => 'Code Quality', 'slug' => 'code-quality', 'status' => 'active']);
        $this->deliverySkill = Skill::create(['name' => 'Delivery', 'slug' => 'delivery-skill', 'status' => 'active']);
    }

    private function user(string $email, string $role): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Delivery Rubric',
            'description' => 'Rubric used for sprint delivery projects.',
            'dimensions' => [
                ['name' => 'quality', 'weight' => 0.6, 'skill_ids' => [$this->qualitySkill->id]],
                ['name' => 'delivery', 'weight' => 0.4, 'skill_ids' => [$this->deliverySkill->id]],
            ],
        ], $overrides);
    }

    private function createDraft(array $overrides = []): int
    {
        $response = $this->postJson('/api/v1/admin/rubrics', $this->payload($overrides));
        $response->assertStatus(201);

        return (int) $response->json('data.rubric.id');
    }

    public function test_an_admin_creates_a_draft_rubric_with_dimensions_and_skill_mappings(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/rubrics', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('data.rubric.status', Rubric::STATUS_DRAFT)
            ->assertJsonPath('data.rubric.version', 1)
            ->assertJsonCount(2, 'data.rubric.dimensions');

        $this->assertDatabaseHas('rubric_dimensions', ['rubric_id' => $response->json('data.rubric.id'), 'name' => 'quality']);
        $this->assertDatabaseCount('rubric_dimension_skills', 2);
    }

    public function test_a_non_admin_cannot_manage_rubrics(): void
    {
        Sanctum::actingAs($this->user('learner@test.com', 'learner'));

        $this->postJson('/api/v1/admin/rubrics', $this->payload())->assertStatus(403);
    }

    public function test_publishing_is_blocked_when_weights_are_not_normalized(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft([
            'dimensions' => [
                ['name' => 'quality', 'weight' => 0.6, 'skill_ids' => [$this->qualitySkill->id]],
                ['name' => 'delivery', 'weight' => 0.2, 'skill_ids' => [$this->deliverySkill->id]],
            ],
        ]);

        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Ready for sprint five.'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RUBRIC_WEIGHTS_NOT_NORMALIZED');
    }

    public function test_publishing_is_blocked_when_a_dimension_has_no_skill_mapping(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft([
            'dimensions' => [
                ['name' => 'quality', 'weight' => 0.5, 'skill_ids' => [$this->qualitySkill->id]],
                ['name' => 'delivery', 'weight' => 0.5, 'skill_ids' => []],
            ],
        ]);

        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Ready for sprint five.'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RUBRIC_SKILL_MAPPING_REQUIRED')
            ->assertJsonPath('details.dimensions.0', 'delivery');
    }

    public function test_publishing_records_the_approver_reason_and_effective_date(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft();

        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", [
            'reason' => 'Approved by the academic board.',
            'effective_date' => '2026-12-01',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.rubric.status', Rubric::STATUS_PUBLISHED)
            ->assertJsonPath('data.rubric.approved_by', $this->admin->id)
            ->assertJsonPath('data.rubric.approval_reason', 'Approved by the academic board.')
            ->assertJsonPath('data.rubric.effective_date', '2026-12-01');
    }

    public function test_a_published_version_cannot_be_edited(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft();
        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Approved.'])->assertStatus(200);

        $this->patchJson("/api/v1/admin/rubric-versions/{$id}", ['title' => 'Changed title'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RUBRIC_VERSION_IMMUTABLE');
    }

    public function test_a_new_version_copies_the_published_dimensions_and_mappings(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft();
        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Approved.'])->assertStatus(200);

        $response = $this->postJson("/api/v1/admin/rubrics/{$id}/versions");

        $response->assertStatus(201)
            ->assertJsonPath('data.rubric.version', 2)
            ->assertJsonPath('data.rubric.status', Rubric::STATUS_DRAFT)
            ->assertJsonCount(2, 'data.rubric.dimensions');

        $this->assertSame(
            Rubric::STATUS_PUBLISHED,
            Rubric::findOrFail($id)->status,
            'The published version must stay untouched when a new draft version is created.'
        );
    }

    public function test_deactivating_a_version_removes_it_from_project_selection(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft();
        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Approved.'])->assertStatus(200);

        $companyAdmin = $this->user('company@test.com', 'company_admin');

        Sanctum::actingAs($companyAdmin);
        $this->getJson('/api/v1/rubrics')->assertStatus(200)->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/rubric-versions/{$id}/deactivate", ['reason' => 'Defective weights.'])
            ->assertStatus(200)
            ->assertJsonPath('data.rubric.status', Rubric::STATUS_DEACTIVATED);

        Sanctum::actingAs($companyAdmin);
        $this->getJson('/api/v1/rubrics')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_the_admin_list_groups_versions_under_their_family(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->createDraft();
        $this->postJson("/api/v1/admin/rubric-versions/{$id}/publish", ['reason' => 'Approved.'])->assertStatus(200);
        $this->postJson("/api/v1/admin/rubrics/{$id}/versions")->assertStatus(201);

        $this->getJson('/api/v1/admin/rubrics')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.rubrics')
            ->assertJsonPath('data.rubrics.0.latest_version', 2)
            ->assertJsonCount(2, 'data.rubrics.0.versions');
    }
}
