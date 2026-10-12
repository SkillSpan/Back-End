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
use Tests\Feature\Intelligence\RoadmapActionContractTest;
use Tests\TestCase;

/**
 * BA-03 — the Project Type / Difficulty contract, frozen.
 *
 * WHY THIS EXISTS
 * ---------------
 * The frontend shows five "project" cards (Practice Task / Simulation Project /
 * Real Project / Assessment / Capstone) and four difficulty labels (Beginner /
 * Intermediate / Advanced / Expert). Neither vocabulary belongs to `projects`:
 *
 *   - the five card labels are `roadmap_actions.type` values (plus the
 *     display-only "Capstone" milestone, which has no backend value at all);
 *   - `projects.type` has exactly TWO values, and `projects.difficulty` is a
 *     plain `decimal(3,2)` in 0–5, not an enum.
 *
 * The mapping is documented in PROJECT_TYPE_DIFFICULTY_CONTRACT.md. This test
 * pins the backend half of that document LITERALLY, so the doc cannot go stale
 * without a red test: if someone adds, renames or drops a value, the frozen
 * constants below stop matching and this file fails on purpose.
 *
 * The pre-existing tests already pin the REJECTION side (an unknown type 422s).
 * What was missing — and what this file adds — is the ACCEPTANCE side (every
 * official value really is writable), the difficulty boundaries (0 and 5 are
 * inclusive), and the literal vocabulary guard.
 *
 * @see ProjectManagementWorkflowTest::test_creation_validates_the_project_type
 * @see ProjectCatalogTest::test_invalid_type_filter_is_rejected
 * @see RoadmapActionContractTest::test_unknown_action_type_is_rejected_instead_of_falling_back_to_practice
 */
class ProjectTypeDifficultyContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * BA-03 — FROZEN. The single source of truth for `projects.type`.
     *
     * Mirrored by three places that must never disagree: the
     * `create_projects_table` enum, StoreProjectRequest's `Rule::in`, and
     * ProjectController's catalog filter (`in:simulation,company_sponsored`).
     */
    private const PROJECT_TYPES = ['simulation', 'company_sponsored'];

    /**
     * BA-03 — FROZEN. Mirrors the `roadmap_actions.type` enum and
     * RoadmapAction::TYPES. These are the four card labels the roadmap
     * renders, plus `resource` (a learning resource, not a project card).
     */
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

    // -----------------------------------------------------------------
    // Vocabulary guards — the literal half of the document
    // -----------------------------------------------------------------

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

        // Every OFFICIAL value is writable...
        foreach (self::PROJECT_TYPES as $type) {
            $this->postJson('/api/v1/projects', $this->payload([
                'type' => $type,
                'title' => 'Official '.$type,
            ]))
                ->assertStatus(201)
                ->assertJsonPath('data.type', $type);
        }

        // ...and the set is CLOSED: nothing else is. `capstone`,
        // `real_project` and `simulation_project` are roadmap/frontend words,
        // not project types — none of them may reach `projects.type`.
        //
        // NOTE: a trailing-space variant is deliberately absent — Laravel's
        // TrimStrings middleware normalises it to the official value before
        // validation, so it is accepted for the right reason, not by accident.
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

    // -----------------------------------------------------------------
    // Difficulty — a number, and the whole 0–5 range is legal
    // -----------------------------------------------------------------

    public function test_difficulty_accepts_the_full_inclusive_0_to_5_range(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        foreach ([0, 0.5, 2.5, 3, 4.25, 5] as $difficulty) {
            $response = $this->postJson('/api/v1/projects', $this->payload([
                'difficulty' => $difficulty,
                'title' => 'Difficulty '.$difficulty,
            ]));

            $response->assertStatus(201);

            // Compared numerically: 0 and 5 are legitimately serialised as
            // integers, so a strict type check would be a false negative.
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

    // -----------------------------------------------------------------
    // The catalog list filter speaks the same closed vocabulary
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Update — the vocabulary holds on the partial-update path too
    // -----------------------------------------------------------------

    public function test_update_accepts_a_switch_between_the_two_official_types(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $id = $this->postJson('/api/v1/projects', $this->payload(['type' => 'company_sponsored']))
            ->assertStatus(201)
            ->json('data.id');

        // company_sponsored → simulation is a legitimate type change on a
        // draft: the service re-runs the ownership rules for the new type.
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

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    /**
     * A learner WITH a student profile — the catalog answers 422
     * STUDENT_PROFILE_NOT_FOUND without one, which would mask the filter
     * behaviour this test is about.
     */
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

    /**
     * The career role every project must target, with its test skill approved
     * in the mapping so the project may require it.
     */
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

    /**
     * A complete, submittable creation payload.
     *
     * @return array<string, mixed>
     */
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
