<?php

namespace Tests\Feature\CareerRoles;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessment;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Baseline\BaselineAssessmentService;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\MvpCatalogSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The MVP availability contract: exactly four career roles are usable, every
 * other role is listed as "Coming Soon".
 *
 * WHAT "COMING SOON" MEANS
 * ------------------------
 * A Coming Soon role is not hidden and not deleted. It is returned by the
 * listing API with `is_available = false`, it keeps its id, its required
 * skills, its questions and its specialization links, and it stays readable
 * through GET /career-roles/{id}. What it cannot do is be USED: it cannot be
 * selected for an assessment, and a request that tries is refused — both at the
 * HTTP layer and again in the service, so a caller cannot bypass the rule.
 *
 * Availability is the existing `career_roles.is_active` column; no new
 * availability system was introduced.
 */
class CareerRoleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The only roles the MVP publishes.
     *
     * @var list<string>
     */
    private const AVAILABLE = [
        'frontend-developer',
        'backend-developer',
        'full-stack-developer',
        'mobile-developer',
    ];

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
            MvpCatalogSeeder::class,
        ]);
    }

    // ------------------------------------------------ listing

    public function test_the_listing_returns_every_existing_role(): void
    {
        $published = CareerRole::where('status', 'approved')->count();

        $this->assertGreaterThan(4, $published, 'The catalog must keep its other roles.');

        Sanctum::actingAs($this->learner());

        $response = $this->getJson('/api/v1/career-roles?per_page=100')->assertOk();

        $this->assertSame($published, $response->json('data.total'));

        // Walk every page: nothing may be dropped by the availability filter.
        $this->assertCount($published, $this->allRoles());
    }

    public function test_exactly_four_roles_are_marked_available(): void
    {
        Sanctum::actingAs($this->learner());

        $available = array_values(array_filter(
            $this->allRoles(),
            fn (array $role) => $role['is_available'] === true,
        ));

        $slugs = array_column($available, 'slug');
        sort($slugs);

        $expected = self::AVAILABLE;
        sort($expected);

        $this->assertSame($expected, $slugs);
    }

    public function test_every_other_role_is_marked_coming_soon(): void
    {
        Sanctum::actingAs($this->learner());

        $comingSoon = array_filter($this->allRoles(), fn (array $role) => $role['is_available'] === false);

        $this->assertNotEmpty($comingSoon);

        foreach ($comingSoon as $role) {
            $this->assertNotContains($role['slug'], self::AVAILABLE);
            // Coming Soon is a state, not a deletion: the row is still there.
            $this->assertTrue(
                CareerRole::where('slug', $role['slug'])->where('status', 'approved')->exists(),
                "[{$role['slug']}] must still exist as an approved role.",
            );
        }
    }

    public function test_the_detail_endpoint_reports_availability_too(): void
    {
        Sanctum::actingAs($this->learner());

        $available = CareerRole::where('slug', 'backend-developer')->firstOrFail();
        $comingSoon = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->getJson('/api/v1/career-roles/'.$available->id)
            ->assertOk()
            ->assertJsonPath('data.is_available', true);

        // Readable — Coming Soon must not 404.
        $this->getJson('/api/v1/career-roles/'.$comingSoon->id)
            ->assertOk()
            ->assertJsonPath('data.is_available', false);
    }

    // ------------------------------------------------ assessment: allowed

    public function test_a_learner_can_start_an_assessment_for_each_available_role(): void
    {
        foreach (self::AVAILABLE as $slug) {
            $role = CareerRole::where('slug', $slug)->firstOrFail();

            // A fresh learner per role keeps the assertions independent.
            Sanctum::actingAs($this->learner());

            $response = $this->postJson('/api/v1/baseline-assessments', [
                'career_role_id' => $role->id,
            ])->assertStatus(201);

            $this->assertSame($role->id, $response->json('data.career_role_id'));
            $this->assertNotEmpty($response->json('data.questions'), "[{$slug}] started with no questions.");
        }
    }

    public function test_the_assessment_payload_for_an_available_role_is_unchanged(): void
    {
        Sanctum::actingAs($this->learner());

        $role = CareerRole::where('slug', 'backend-developer')->firstOrFail();

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // The existing contract: questions are shown, grading keys are not.
        $payload = $response->getContent();

        $this->assertStringNotContainsString('correct_answer', $payload);
        $this->assertStringNotContainsString('scoring_rule', $payload);

        foreach ($response->json('data.questions') as $question) {
            $this->assertArrayHasKey('item_id', $question);
            $this->assertArrayHasKey('skill_id', $question);
            $this->assertArrayNotHasKey('correct_answer', $question);
        }
    }

    // ------------------------------------------------ assessment: refused

    public function test_a_coming_soon_role_cannot_start_an_assessment_over_http(): void
    {
        Sanctum::actingAs($this->learner());

        $comingSoon = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $comingSoon->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id')
            ->assertJsonPath('errors.career_role_id.0', 'The selected career role is not available yet.');

        // Nothing was written.
        $this->assertSame(0, BaselineAssessment::count());
    }

    /**
     * The HTTP rule is not the only line of defence: the service refuses too,
     * so an internal caller (a command, a job, a future route) cannot bypass it.
     *
     * Every Coming Soon role is checked here rather than a sample.
     */
    public function test_the_service_refuses_every_coming_soon_role(): void
    {
        $service = app(BaselineAssessmentService::class);
        $profile = $this->learner()->studentProfile;

        $comingSoon = CareerRole::where('status', 'approved')
            ->where('is_active', false)
            ->get();

        $this->assertGreaterThan(100, $comingSoon->count());

        foreach ($comingSoon as $role) {
            try {
                $service->start($profile, $role, 'test-request-id');
                $this->fail("The service started an assessment for Coming Soon role [{$role->slug}].");
            } catch (BaselineAssessmentException $e) {
                $this->assertSame(422, $e->status);
                $this->assertSame('CAREER_ROLE_INACTIVE', $e->codeName);
            }
        }

        $this->assertSame(0, BaselineAssessment::count());
    }

    public function test_a_coming_soon_role_cannot_be_selected_as_the_primary_role(): void
    {
        $user = $this->learner();
        $user->studentProfile->forceFill(['primary_career_role_id' => null])->save();
        Sanctum::actingAs($user);

        $comingSoon = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $comingSoon->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_career_role_id');
    }

    // ------------------------------------------------ data preservation

    public function test_applying_the_policy_preserves_ids_skills_questions_and_links(): void
    {
        // Snapshot the catalog as it is *before* the policy is re-applied.
        $before = CareerRole::query()
            ->orderBy('id')
            ->get(['id', 'slug'])
            ->map(fn (CareerRole $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'skills' => DB::table('career_role_skills')
                    ->where('career_role_id', $role->id)
                    ->orderBy('skill_id')
                    ->pluck('skill_id')
                    ->all(),
                'specializations' => DB::table('career_role_specialization')
                    ->where('career_role_id', $role->id)
                    ->orderBy('specialization_id')
                    ->pluck('specialization_id')
                    ->all(),
            ])
            ->all();

        $counts = [
            'roles' => CareerRole::count(),
            'role_skills' => DB::table('career_role_skills')->count(),
            'questions' => DB::table('baseline_assessment_items')->count(),
            'pivot' => DB::table('career_role_specialization')->count(),
            'skills' => DB::table('skills')->count(),
        ];

        // Re-running the policy must not change any of it.
        $this->seed(MvpCatalogSeeder::class);

        $after = CareerRole::query()
            ->orderBy('id')
            ->get(['id', 'slug'])
            ->map(fn (CareerRole $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'skills' => DB::table('career_role_skills')
                    ->where('career_role_id', $role->id)
                    ->orderBy('skill_id')
                    ->pluck('skill_id')
                    ->all(),
                'specializations' => DB::table('career_role_specialization')
                    ->where('career_role_id', $role->id)
                    ->orderBy('specialization_id')
                    ->pluck('specialization_id')
                    ->all(),
            ])
            ->all();

        $this->assertSame($before, $after);

        $this->assertSame($counts, [
            'roles' => CareerRole::count(),
            'role_skills' => DB::table('career_role_skills')->count(),
            'questions' => DB::table('baseline_assessment_items')->count(),
            'pivot' => DB::table('career_role_specialization')->count(),
            'skills' => DB::table('skills')->count(),
        ]);
    }

    public function test_the_four_available_roles_exist_and_are_approved(): void
    {
        foreach (self::AVAILABLE as $slug) {
            $role = CareerRole::where('slug', $slug)->first();

            $this->assertNotNull($role, "The MVP requires the role [{$slug}].");
            $this->assertSame('approved', $role->status);
            $this->assertTrue((bool) $role->is_active);
            $this->assertGreaterThan(0, $role->roleSkills()->count(), "[{$slug}] must keep its required skills.");
        }

        // No duplicates were created for them.
        $this->assertSame(4, CareerRole::whereIn('slug', self::AVAILABLE)->count());
        $this->assertSame(4, CareerRole::where('is_active', true)->count());
    }

    // ------------------------------------------------ helpers

    /**
     * Every role the listing API returns, across all pages.
     *
     * @return list<array<string, mixed>>
     */
    private function allRoles(): array
    {
        $roles = [];
        $page = 1;

        do {
            $payload = $this->getJson('/api/v1/career-roles?per_page=100&page='.$page)
                ->assertOk()
                ->json('data');

            $roles = array_merge($roles, $payload['data'] ?? []);
            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $roles;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'MVP Learner',
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
