<?php

namespace Tests\Feature\CareerRoles;

use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\BaselineAssessmentItemSeeder;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\MvpCatalogSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The career-role catalogue: listing, availability flags and ordering.
 *
 * THE POLICY UNDER TEST
 * ---------------------
 * Four roles are available (config/mvp.php); every other approved role is
 * listed as Coming Soon. Nothing is hidden or deleted — the listing returns the
 * whole published catalog and marks each item with `is_available`.
 *
 * Order: available first, then Coming Soon. Inside each block the roles of the
 * chosen specialization come first (config/mvp.php), then everything else in a
 * neutral order. The specialization only ORDERS; it never filters.
 *
 * The availability-specific acceptance tests (assessment allowed/refused, data
 * preservation) live in CareerRoleAvailabilityTest.
 */
class MvpCareerRoleCatalogTest extends TestCase
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
            MvpCatalogSeeder::class,
        ]);

        Sanctum::actingAs($this->learner());
    }

    // ------------------------------------------------ listing

    public function test_every_approved_role_is_listed_with_an_availability_flag(): void
    {
        $approved = CareerRole::where('status', 'approved')->count();

        $response = $this->getJson('/api/v1/career-roles?per_page=100')->assertOk();

        $this->assertSame($approved, $response->json('data.total'));

        foreach ($response->json('data.data') as $item) {
            $this->assertArrayHasKey('is_available', $item);
            $this->assertIsBool($item['is_available']);
        }

        // Coming Soon roles are listed, not hidden.
        $comingSoon = array_filter($this->allItems(), fn (array $item) => $item['is_available'] === false);
        $this->assertNotEmpty($comingSoon);
    }

    public function test_a_draft_role_is_never_listed_even_when_available(): void
    {
        $draft = CareerRole::forceCreate([
            'title' => 'Draft Role',
            'slug' => 'draft-role',
            'version' => 1,
            'status' => 'draft',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $titles = $this->allTitles('/api/v1/career-roles?per_page=100');

        $this->assertNotContains($draft->title, $titles);
    }

    public function test_availability_changes_never_touch_skills_questions_or_links(): void
    {
        // A Coming Soon role keeps everything it had.
        $role = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->assertFalse((bool) $role->is_active);
        $this->assertGreaterThan(0, $role->roleSkills()->count());
        $this->assertGreaterThan(0, $this->questionsForRole($role));
        $this->assertGreaterThan(0, $role->specializations()->count());
    }

    // ------------------------------------------------ ordering

    public function test_available_roles_are_listed_before_coming_soon_roles(): void
    {
        $computerScience = Specialization::where('name', 'Computer Science')->firstOrFail();

        $items = $this->allItems('/api/v1/career-roles?specialization_id='.$computerScience->id.'&per_page=100');

        $firstFour = array_slice($items, 0, 4);

        foreach ($firstFour as $item) {
            $this->assertTrue($item['is_available'], "[{$item['title']}] must be in the available block.");
        }

        // Everything after the available block is Coming Soon.
        foreach (array_slice($items, 4) as $item) {
            $this->assertFalse($item['is_available'], "[{$item['title']}] must be Coming Soon.");
        }
    }

    public function test_priority_order_applies_inside_the_available_block(): void
    {
        $computerScience = Specialization::where('name', 'Computer Science')->firstOrFail();

        $titles = $this->allTitles('/api/v1/career-roles?specialization_id='.$computerScience->id.'&per_page=100');

        // The four available roles, in this specialization's priority order
        // (frontend, backend, full-stack, then mobile — software-engineer sits
        // between them in the config but is Coming Soon).
        $this->assertSame(
            ['Frontend Developer', 'Backend Developer', 'Full Stack Developer', 'Mobile Developer'],
            array_slice($titles, 0, 4),
        );
    }

    public function test_priority_order_applies_inside_the_coming_soon_block(): void
    {
        $computerScience = Specialization::where('name', 'Computer Science')->firstOrFail();

        $titles = $this->allTitles('/api/v1/career-roles?specialization_id='.$computerScience->id.'&per_page=100');

        // Computer Science's own Coming Soon roles lead the second block, in
        // the configured order.
        $this->assertSame(
            ['Software Engineer', 'Data Analyst', 'Data Scientist', 'AI Engineer', 'Machine Learning Engineer'],
            array_slice($titles, 4, 5),
        );
    }

    public function test_two_specializations_return_the_same_set_in_different_orders(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();
        $ds = Specialization::where('name', 'Data Science')->firstOrFail();

        $csTitles = $this->allTitles('/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=100');
        $dsTitles = $this->allTitles('/api/v1/career-roles?specialization_id='.$ds->id.'&per_page=100');

        $csSorted = $csTitles;
        $dsSorted = $dsTitles;
        sort($csSorted);
        sort($dsSorted);

        $this->assertSame($csSorted, $dsSorted, 'Both specializations must list the same roles.');
        $this->assertNotSame($csTitles, $dsTitles, 'The priority order must depend on the specialization.');
    }

    public function test_the_free_track_applies_no_priority_list(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();

        $items = $this->allItems('/api/v1/career-roles?specialization_id='.$free->id.'&per_page=100');

        // The availability rank still applies…
        foreach (array_slice($items, 0, 4) as $item) {
            $this->assertTrue($item['is_available']);
        }

        // …but no specialization priority does: the Coming Soon block starts
        // with the neutral order (highest id first).
        $neutralFirst = CareerRole::where('status', 'approved')
            ->where('is_active', false)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->value('title');

        $this->assertSame($neutralFirst, $items[4]['title']);
    }

    public function test_an_inactive_specialization_cannot_be_used_as_a_filter(): void
    {
        $legacy = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        $this->assertFalse((bool) $legacy->is_active);

        $this->getJson('/api/v1/career-roles?specialization_id='.$legacy->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    public function test_pagination_never_skips_or_repeats_a_role(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();

        $first = $this->getJson('/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=15')->assertOk()->json('data');
        $total = (int) $first['total'];
        $lastPage = (int) $first['last_page'];

        $collected = [];

        for ($page = 1; $page <= $lastPage; $page++) {
            $payload = $this->getJson(
                '/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=15&page='.$page
            )->assertOk()->json('data');

            foreach ($payload['data'] as $item) {
                $collected[] = $item['id'];
            }
        }

        $this->assertSame($total, count($collected), 'Pagination lost or duplicated roles.');
        $this->assertSame($total, count(array_unique($collected)), 'A role was returned on two pages.');
    }

    public function test_search_matches_coming_soon_roles_too(): void
    {
        $response = $this->getJson('/api/v1/career-roles?search=engineer&per_page=100')->assertOk();

        $items = collect($response->json('data.data'))->keyBy('title');

        $this->assertTrue($items->has('Software Engineer'));
        $this->assertFalse($items['Software Engineer']['is_available']);

        // A Coming Soon role is searchable — it is listed, just not usable.
        $this->assertTrue($items->has('QA Engineer'));
        $this->assertFalse($items['QA Engineer']['is_available']);
    }

    // ------------------------------------------------ gates

    public function test_a_coming_soon_role_cannot_start_a_new_assessment(): void
    {
        $role = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    public function test_an_available_role_can_still_start_an_assessment(): void
    {
        $role = CareerRole::where('slug', 'backend-developer')->firstOrFail();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.career_role_id', $role->id);
    }

    public function test_a_coming_soon_role_stays_readable_for_history(): void
    {
        $role = CareerRole::where('slug', 'data-scientist')->firstOrFail();

        $this->getJson('/api/v1/career-roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('data.id', $role->id)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_available', false);

        $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertOk()
            ->assertJsonPath('data.career_role.id', $role->id);
    }

    public function test_a_profile_may_keep_its_coming_soon_role_but_may_not_pick_a_new_one(): void
    {
        $held = CareerRole::where('slug', 'data-scientist')->firstOrFail();
        $other = CareerRole::where('slug', 'qa-engineer')->firstOrFail();

        $user = $this->learner();
        $user->studentProfile->forceFill(['primary_career_role_id' => $held->id])->save();
        Sanctum::actingAs($user);

        // Re-sending the value the profile already holds is a no-op, not a new
        // selection: publishing fewer roles must not invalidate existing
        // profiles.
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $held->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Choosing a DIFFERENT Coming Soon role is a new selection and is
        // refused.
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_career_role_id');
    }

    // ------------------------------------------------ policy integrity

    public function test_every_configured_available_slug_exists_in_the_catalog(): void
    {
        $slugs = config('mvp.available_career_role_slugs');

        $this->assertCount(4, $slugs);

        foreach ($slugs as $slug) {
            $this->assertTrue(
                CareerRole::where('slug', $slug)->exists(),
                "The policy lists [{$slug}], but no such career role exists.",
            );
        }

        // Exactly these four are active — nothing else slipped through.
        $expected = $slugs;
        sort($expected);

        $this->assertSame(
            $expected,
            CareerRole::where('is_active', true)->orderBy('slug')->pluck('slug')->all(),
        );
    }

    // ------------------------------------------------ response contract

    public function test_the_career_role_payload_shape_is_unchanged(): void
    {
        $response = $this->getJson('/api/v1/career-roles')->assertOk();

        // Top-level envelope.
        $this->assertSame(
            ['success', 'message', 'data', 'request_id'],
            array_keys($response->json()),
        );
        $this->assertSame('Career roles retrieved successfully.', $response->json('message'));

        // Laravel length-aware paginator, exactly as documented.
        $this->assertSame(
            [
                'current_page', 'data', 'first_page_url', 'from', 'last_page',
                'last_page_url', 'links', 'next_page_url', 'path', 'per_page',
                'prev_page_url', 'to', 'total',
            ],
            array_keys($response->json('data')),
        );

        // Item shape: the previous keys are all still there; `is_available` is
        // the single additive field, and the raw `is_active` stays hidden.
        $item = $response->json('data.data.0');

        $this->assertArrayNotHasKey('is_active', $item);
        $this->assertSame(
            [
                'id', 'title', 'slug', 'version', 'status', 'effective_date',
                'description', 'created_at', 'updated_at', 'skills_count',
                'is_available',
            ],
            array_keys($item),
        );
    }

    // ------------------------------------------------ helpers

    /**
     * @return list<array<string, mixed>>
     */
    private function allItems(string $url = '/api/v1/career-roles?per_page=100'): array
    {
        $items = [];
        $page = 1;

        do {
            $payload = $this->getJson($url.(str_contains($url, '?') ? '&' : '?').'page='.$page)
                ->assertOk()
                ->json('data');

            $items = array_merge($items, $payload['data'] ?? []);
            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }

    /**
     * @return list<string>
     */
    private function allTitles(string $url): array
    {
        return array_column($this->allItems($url), 'title');
    }

    private function questionsForRole(CareerRole $role): int
    {
        $skillIds = CareerRoleSkill::where('career_role_id', $role->id)->pluck('skill_id')->all();

        if ($skillIds === []) {
            return 0;
        }

        return BaselineAssessmentItem::where('assessment_version', 'v1.0')
            ->where('is_active', true)
            ->whereIn('skill_id', $skillIds)
            ->count();
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

        return $user->fresh();
    }
}
