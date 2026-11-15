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

    public function test_the_free_track_has_no_pivot_rows(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();

        $this->assertSame(0, $free->careerRoles()->count());
    }

    public function test_the_free_track_returns_every_selectable_career_role(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();
        $software = Specialization::where('name', 'Software Engineering')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $freeTotal = $this->getJson('/api/v1/career-roles?specialization_id='.$free->id)->assertOk()->json('data.total');
        $softwareTotal = $this->getJson('/api/v1/career-roles?specialization_id='.$software->id)->assertOk()->json('data.total');

        $selectable = CareerRole::where('status', 'approved')->where('is_active', true)->count();

        $this->assertSame($selectable, $freeTotal);
        $this->assertSame($selectable, $softwareTotal);
    }

    public function test_a_normal_specialization_offers_roles_outside_its_pivot(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $titles = $this->allTitles('?specialization_id='.$cyber->id.'&per_page=100');

        $this->assertContains('Security Analyst', $titles);
        $this->assertContains('Frontend Developer', $titles);
    }

    private function allTitles(string $query): array
    {
        $titles = [];
        $page = 1;

        do {
            $payload = $this->getJson('/api/v1/career-roles'.$query.'&page='.$page)
                ->assertOk()
                ->json('data');

            $titles = array_merge($titles, array_column($payload['data'] ?? [], 'title'));
            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $titles;
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

    public function test_a_normal_specialization_accepts_a_role_outside_its_pivot(): void
    {
        $cyber = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $frontend = CareerRole::where('slug', 'frontend-developer')->firstOrFail();

        Sanctum::actingAs($this->learnerWithProfile());

        $this->postJson('/api/v1/baseline-assessments', [
            'specialization_id' => $cyber->id,
            'career_role_id' => $frontend->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.career_role_id', $frontend->id);
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
