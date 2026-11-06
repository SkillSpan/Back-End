<?php

namespace Tests\Feature\Projects;

use App\Models\CareerRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Projects with a dead date are invisible to every learner.
 *
 * `GET /api/v1/projects` filters on `application_deadline >= today` and
 * `end_date >= today` (ProjectAccessService), so a project whose status reads
 * `open` can still be missing from the learner feed. That is correct filtering
 * — but it used to be UNFIXABLE, because editing is only allowed in `draft` /
 * `changes_requested`, so once such a project was opened nothing could repair
 * it. This suite pins the two guards that stop the trap from forming:
 *
 *   1. the request rejects a date before today, and
 *   2. the completeness check refuses to submit / open a project that holds a
 *      past date anyway (cloned rows, direct DB edits, older data).
 */
class ProjectDateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function company(): User
    {
        $organization = Organization::forceCreate([
            'name' => 'Date Guard Co',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $user = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner-dates@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'company_admin')->first()->id);
        $user->organizations()->attach($organization->id, [
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin-dates@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user->fresh();
    }

    private function careerRole(): CareerRole
    {
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        $role = CareerRole::create([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer',
            'version' => 1,
            'status' => 'approved',
        ]);

        $role->roleSkills()->create([
            'skill_id' => $skill->id,
            'required_level' => 3,
            'importance_weight' => 1,
            'is_critical' => true,
        ]);

        return $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $role = $this->careerRole();

        return array_merge([
            'type' => 'company_sponsored',
            'title' => 'Date Guard Project',
            'description' => 'A project used to pin the date guards.',
            'objectives' => 'Prove a past date cannot be stored.',
            'capacity' => 2,
            'confidentiality' => 'public',
            'career_role_id' => $role->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'required_skills' => [
                ['skill_id' => $role->roleSkills()->first()->skill_id, 'minimum_level' => 3],
            ],
            'roles' => [
                ['title' => 'Backend Developer'],
            ],
        ], $overrides);
    }

    /**
     * Persist a project straight into `open` with a deadline in the past,
     * bypassing the workflow — the state real data was left in.
     */
    private function expiredOpenProject(): Project
    {
        $owner = $this->company();

        return Project::create([
            'organization_id' => $owner->organizations()->first()->id,
            'owner_id' => $owner->id,
            'title' => 'Expired Project',
            'description' => 'Opened with a deadline that had already passed.',
            'objectives' => 'Reproduce the invisible but open project.',
            'type' => 'company_sponsored',
            'status' => Project::STATUS_OPEN,
            'confidentiality' => 'public',
            'capacity' => 2,
            'difficulty' => 3,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'application_deadline' => now()->subDay()->toDateString(),
        ]);
    }

    public function test_a_deadline_in_the_past_is_rejected_on_create(): void
    {
        Sanctum::actingAs($this->company());

        $this->postJson('/api/v1/projects', $this->payload([
            'application_deadline' => now()->subDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('application_deadline');
    }

    public function test_a_start_date_in_the_past_is_rejected_on_create(): void
    {
        Sanctum::actingAs($this->company());

        $this->postJson('/api/v1/projects', $this->payload([
            'start_date' => now()->subDays(3)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    public function test_an_end_date_in_the_past_is_rejected_on_create(): void
    {
        Sanctum::actingAs($this->company());

        $this->postJson('/api/v1/projects', $this->payload([
            'end_date' => now()->subDays(3)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_todays_deadline_is_accepted(): void
    {
        Sanctum::actingAs($this->company());

        $this->postJson('/api/v1/projects', $this->payload([
            'application_deadline' => now()->toDateString(),
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.status', Project::STATUS_DRAFT);
    }

    public function test_a_past_deadline_is_rejected_on_update(): void
    {
        $owner = $this->company();
        Sanctum::actingAs($owner);

        $project = Project::create([
            'organization_id' => $owner->organizations()->first()->id,
            'owner_id' => $owner->id,
            'title' => 'Draft With Dates',
            'description' => 'Draft.',
            'objectives' => 'Draft.',
            'type' => 'company_sponsored',
            'status' => Project::STATUS_DRAFT,
            'confidentiality' => 'public',
            'capacity' => 2,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, [
            'application_deadline' => now()->subMonth()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('application_deadline');

        $this->assertSame(
            now()->addDays(10)->toDateString(),
            $project->fresh()->application_deadline->toDateString(),
        );
    }

    public function test_an_expired_project_cannot_be_opened(): void
    {
        $project = $this->expiredOpenProject();
        $owner = $project->owner;

        // `submitted → changes_requested` then `→ submitted → approved` is how
        // real data reached this state; forcing it keeps the test on the guard.
        $project->forceFill([
            'status' => Project::STATUS_APPROVED,
            'application_deadline' => now()->subDay()->toDateString(),
        ])->save();

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/open')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE');

        $this->assertContains(
            'application_deadline_in_the_past',
            $response->json('details.missing'),
        );

        $this->assertSame(Project::STATUS_APPROVED, $project->fresh()->status);
    }

    public function test_an_expired_project_cannot_be_submitted(): void
    {
        $project = $this->expiredOpenProject();

        $project->forceFill([
            'status' => Project::STATUS_DRAFT,
            'application_deadline' => now()->subDay()->toDateString(),
        ])->save();

        $this->actingAs($project->owner);

        $response = $this->postJson('/api/v1/projects/'.$project->id.'/submit')
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE');

        $this->assertContains(
            'application_deadline_in_the_past',
            $response->json('details.missing'),
        );
    }

    public function test_the_learner_feed_excludes_an_expired_open_project(): void
    {
        $project = $this->expiredOpenProject();

        $learner = User::forceCreate([
            'name' => 'Learner',
            'email' => 'learner-dates@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $learner->roles()->attach(Role::where('slug', 'learner')->first()->id);
        $learner->studentProfile()->create([
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($learner->fresh());

        $ids = collect($this->getJson('/api/v1/projects')->assertStatus(200)->json('data.data'))
            ->pluck('id')
            ->all();

        // The row really is `open` and public — only the date hides it.
        $this->assertSame(Project::STATUS_OPEN, $project->status);
        $this->assertNotContains($project->id, $ids);
    }
}
