<?php

namespace Tests\Feature\CareerRoles;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The "Self-Learning / Free Track" specialization.
 *
 * Its available career roles are EVERY role in the system, resolved from its
 * `is_free_track` flag rather than from pivot rows, so a learner who studied
 * outside a formal academic track can pick any role. A normal specialization
 * keeps the restrictive behaviour.
 */
class FreeTrackTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            BaselineAssessmentItemSeeder::class,
        ]);
    }

    public function test_the_free_track_specialization_exists_and_is_flagged(): void
    {
        $free = Specialization::where('is_free_track', true)->first();

        $this->assertNotNull($free);
        $this->assertSame(SpecializationsSeeder::FREE_TRACK_NAME, $free->name);
        $this->assertTrue($free->isFreeTrack());
    }

    /**
     * The whole point: the free track must NOT need a pivot row per role.
     */
    public function test_the_free_track_has_no_pivot_rows(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();

        $this->assertSame(0, $free->careerRoles()->count());
    }

    public function test_the_free_track_returns_every_approved_career_role(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();
        $software = Specialization::where('name', 'Software Engineering')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $freeTotal = $this->getJson('/api/v1/career-roles?specialization_id='.$free->id)->assertOk()->json('data.total');
        $softwareTotal = $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)->assertOk()->json('data.total');

        $this->assertSame(CareerRole::where('status', 'approved')->count(), $freeTotal);
        $this->assertSame($software->careerRoles()->count(), $softwareTotal);
        $this->assertLessThan($freeTotal, $softwareTotal, 'A normal specialization must stay restrictive.');
    }

    public function test_a_normal_specialization_still_returns_only_its_linked_roles(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?specialization_id='.$cyber->id)->json('data.data'),
            'title',
        );

        $this->assertContains('Security Analyst', $titles);
        $this->assertNotContains('Frontend Developer', $titles);
    }

    public function test_the_free_track_accepts_any_career_role_for_an_assessment(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();
        $role = CareerRole::where('slug', 'backend-developer')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $free->id,
            'career_role_id' => $role->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.career_role_id', $role->id);
    }

    public function test_a_normal_specialization_rejects_a_role_it_does_not_offer(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $frontend = CareerRole::where('slug', 'frontend-developer')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $cyber->id,
            'career_role_id' => $frontend->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    public function test_the_public_reference_endpoint_exposes_the_free_track(): void
    {
        $names = array_column(
            $this->getJson('/api/v1/reference/specializations')->assertOk()->json('data'),
            'name',
        );

        $this->assertContains(SpecializationsSeeder::FREE_TRACK_NAME, $names);
    }

    private function learnerWithProfile(): User
    {
        $user = User::forceCreate([
            'name' => 'Self Taught Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }
}
