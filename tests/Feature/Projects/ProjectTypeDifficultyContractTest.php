<?php

namespace Tests\Feature\Projects;

use App\Models\CareerRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTypeDifficultyContractTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_TYPES = ['simulation', 'company_sponsored'];

    private const ROADMAP_ACTION_TYPES = [
        'assessment',
        'resource',
        'practice',
        'simulated_project',
        'real_project',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    public function test_the_roadmap_action_vocabulary_is_exactly_the_documented_five(): void
    {
        $this->assertSame(
            self::ROADMAP_ACTION_TYPES,
            RoadmapAction::TYPES,
            'roadmap_actions.type changed — update PROJECT_TYPE_DIFFICULTY_CONTRACT.md and the FE-09 mapping with it.',
        );
    }

    public function test_the_project_type_vocabulary_is_exactly_the_documented_two(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        foreach (self::PROJECT_TYPES as $type) {
            $this->postJson('/api/v1/projects', $this->payload([
                'type' => $type,
                'title' => 'Official '.$type,
            ]))
                ->assertStatus(201)
                ->assertJsonPath('data.type', $type);
        }

        $unofficial = [
            'capstone',
            'real_project',
            'simulation_project',
            'practice',
            'assessment',
            'resource',
            'company-sponsored',
            'Simulation',
            'SIMULATION',
            '',
        ];

        foreach ($unofficial as $type) {
            $this->postJson('/api/v1/projects', $this->payload(['type' => $type]))
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->assertSame(
            count(self::PROJECT_TYPES),
            Project::query()->distinct()->count('type'),
            'A project row carries a type outside the documented vocabulary.',
        );
    }

    public function test_difficulty_accepts_the_full_inclusive_0_to_5_range(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        foreach ([0, 0.5, 2.5, 3, 4.25, 5] as $difficulty) {
            $response = $this->postJson('/api/v1/projects', $this->payload([
                'difficulty' => $difficulty,
                'title' => 'Difficulty '.$difficulty,
            ]));

            $response->assertStatus(201);

            $this->assertSame(
                (float) $difficulty,
                (float) $response->json('data.difficulty'),
                'difficulty '.$difficulty.' did not round-trip.',
            );
        }
    }

    public function test_difficulty_rejects_values_outside_0_to_5(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        foreach ([-0.01, 5.01, 6, 99] as $difficulty) {
            $this->postJson('/api/v1/projects', $this->payload(['difficulty' => $difficulty]))
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_difficulty_is_nullable_because_it_is_not_an_enum(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $this->postJson('/api/v1/projects', $this->payload(['difficulty' => null]))
            ->assertStatus(201)
            ->assertJsonPath('data.difficulty', null);
    }

    public function test_the_catalog_type_filter_accepts_only_the_official_values(): void
    {
        Sanctum::actingAs($this->learner());

        foreach (self::PROJECT_TYPES as $type) {
            $this->getJson('/api/v1/projects?type='.$type)->assertStatus(200);
        }

        foreach (['capstone', 'real_project', 'hackathon'] as $type) {
            $this->getJson('/api/v1/projects?type='.$type)
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_the_catalog_difficulty_filter_uses_the_same_0_to_5_bounds(): void
    {
        Sanctum::actingAs($this->learner());

        foreach ([0, 2.5, 5] as $difficulty) {
            $this->getJson('/api/v1/projects?difficulty='.$difficulty)->assertStatus(200);
        }

        foreach ([-1, 6, 99] as $difficulty) {
            $this->getJson('/api/v1/projects?difficulty='.$difficulty)
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_update_accepts_a_switch_between_the_two_official_types(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $id = $this->postJson('/api/v1/projects', $this->payload(['type' => 'company_sponsored']))
            ->assertStatus(201)
            ->json('data.id');

        $this->patchJson('/api/v1/projects/'.$id, ['type' => 'simulation'])
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'simulation');
    }

    public function test_update_rejects_an_unofficial_type(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $id = $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(201)
            ->json('data.id');

        $this->patchJson('/api/v1/projects/'.$id, ['type' => 'capstone'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['type']);
    }

    private function organization(): Organization
    {
        return Organization::forceCreate([
            'name' => 'Contract Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);
    }

    private function companyAdmin(): User
    {
        $user = User::forceCreate([
            'name' => 'Contract Admin',
            'email' => 'contract@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'company_admin')->first()->id);
        $user->organizations()->attach($this->organization()->id, [
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Contract Learner',
            'email' => 'contract.learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function skill(): Skill
    {
        return Skill::first() ?? Skill::create([
            'name' => 'PHP',
            'slug' => 'php',
            'category' => 'backend',
        ]);
    }

    private function careerRole(): CareerRole
    {
        $role = CareerRole::first() ?? CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer',
            'version' => 1,
            'status' => 'approved',
        ]);

        $skill = $this->skill();

        if (! $role->roleSkills()->where('skill_id', $skill->id)->exists()) {
            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3,
                'importance_weight' => 1,
                'is_critical' => true,
            ]);
        }

        return $role;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'company_sponsored',
            'title' => 'Contract Project',
            'description' => 'A project used to pin the BA-03 contract.',
            'objectives' => 'Prove the vocabulary is closed.',
            'domain' => 'Software',
            'difficulty' => 3,
            'work_mode' => 'remote',
            'capacity' => 2,
            'confidentiality' => 'public',
            'career_role_id' => $this->careerRole()->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'required_skills' => [
                ['skill_id' => $this->skill()->id, 'minimum_level' => 3, 'is_critical_entry' => true],
            ],
        ], $overrides);
    }
}
