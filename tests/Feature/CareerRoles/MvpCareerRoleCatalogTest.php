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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The MVP career-role catalogue: activation, availability and ordering.
 *
 * These tests pin the policy the MVP introduced:
 *
 *  - a role is offered only when it is `approved` AND `is_active`;
 *  - every active specialization offers EVERY active role (the pivot no longer
 *    filters anything);
 *  - the specialization's priority list only decides the ORDER, and it is
 *    applied before pagination so pages never skip or repeat a role;
 *  - the free track has no priority list and therefore a neutral order;
 *  - deactivating a role never removes its skills, questions or pivot links,
 *    and never blocks reading it.
 *
 * The full seeded catalogue is used on purpose: the assertions are about real
 * slugs from config/mvp.php, so a typo in that file fails here instead of
 * silently producing a neutral order in production.
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

    // ------------------------------------------------ activation

    public function test_only_approved_and_active_roles_are_listed(): void
    {
        $active = CareerRole::where('status', 'approved')->where('is_active', true)->count();

        $response = $this->getJson('/api/v1/career-roles?per_page=100')->assertOk();

        $this->assertSame($active, $response->json('data.total'));

        // A deactivated role is never in the list…
        $excluded = CareerRole::where('slug', 'qa-engineer')->firstOrFail();
        $this->assertFalse((bool) $excluded->is_active);

        $titles = $this->allTitles('/api/v1/career-roles?per_page=100');
        $this->assertNotContains($excluded->title, $titles);

        // …but the row, its skills and its questions still exist.
        $this->assertGreaterThan(0, $excluded->roleSkills()->count());
        $this->assertGreaterThan(0, $this->questionsForRole($excluded));
    }

    public function test_a_draft_role_is_never_listed_even_when_active(): void
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

    public function test_deactivating_a_role_keeps_its_skills_questions_and_pivot_links(): void
    {
        $role = CareerRole::where('slug', 'qa-engineer')->firstOrFail();

        $skillIds = $role->roleSkills()->pluck('skill_id')->all();
        $pivotCount = DB::table('career_role_specialization')->where('career_role_id', $role->id)->count();
        $questionCount = $this->questionsForRole($role);

        $this->assertGreaterThan(0, count($skillIds));
        $this->assertGreaterThan(0, $pivotCount);
        $this->assertGreaterThan(0, $questionCount);
        $this->assertFalse((bool) $role->is_active);
    }

    // ------------------------------------------------ availability

    public function test_every_active_specialization_offers_the_same_roles(): void
    {
        $selectable = CareerRole::where('status', 'approved')->where('is_active', true)->count();

        foreach (config('mvp.specializations') as $name) {
            $specialization = Specialization::where('name', $name)->firstOrFail();

            $total = $this->getJson('/api/v1/career-roles?specialization_id='.$specialization->id)
                ->assertOk()
                ->json('data.total');

            $this->assertSame(
                $selectable,
                $total,
                "Specialization [{$name}] must offer every active role, not only its pivot roles.",
            );
        }
    }

    public function test_the_free_track_offers_the_same_roles_in_a_neutral_order(): void
    {
        $free = Specialization::where('is_free_track', true)->firstOrFail();

        $freeTitles = $this->allTitles('/api/v1/career-roles?specialization_id='.$free->id.'&per_page=100');
        $unfilteredTitles = $this->allTitles('/api/v1/career-roles?per_page=100');

        $this->assertSame($unfilteredTitles, $freeTitles);
    }

    public function test_an_inactive_specialization_cannot_be_used_as_a_filter(): void
    {
        $legacy = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        $this->assertFalse((bool) $legacy->is_active);

        $this->getJson('/api/v1/career-roles?specialization_id='.$legacy->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialization_id');
    }

    // ------------------------------------------------ ordering

    public function test_priority_roles_are_listed_first_in_the_configured_order(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();

        $expected = [];

        foreach (config('mvp.career_role_priority.Computer Science') as $slug) {
            $role = CareerRole::where('slug', $slug)->first();

            if ($role !== null) {
                $expected[] = $role->title;
            }
        }

        $this->assertNotEmpty($expected);

        $titles = $this->allTitles('/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=100');

        $this->assertSame($expected, array_slice($titles, 0, count($expected)));
    }

    public function test_a_priority_role_is_not_repeated_in_the_rest_of_the_list(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();

        $titles = $this->allTitles('/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=100');

        $this->assertSame(
            count($titles),
            count(array_unique($titles)),
            'A role appeared twice in the ordered list.',
        );

        $priorityTitles = [];

        foreach (config('mvp.career_role_priority.Computer Science') as $slug) {
            $role = CareerRole::where('slug', $slug)->first();

            if ($role !== null) {
                $priorityTitles[] = $role->title;
            }
        }

        $rest = array_slice($titles, count($priorityTitles));

        foreach ($priorityTitles as $title) {
            $this->assertNotContains($title, $rest, "Priority role [{$title}] leaked into the rest block.");
        }
    }

    public function test_two_specializations_return_the_same_roles_in_different_orders(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();
        $ds = Specialization::where('name', 'Data Science')->firstOrFail();

        $csTitles = $this->allTitles('/api/v1/career-roles?specialization_id='.$cs->id.'&per_page=100');
        $dsTitles = $this->allTitles('/api/v1/career-roles?specialization_id='.$ds->id.'&per_page=100');

        $csSorted = $csTitles;
        $dsSorted = $dsTitles;
        sort($csSorted);
        sort($dsSorted);

        $this->assertSame($csSorted, $dsSorted, 'Both specializations must offer the same set of roles.');
        $this->assertNotSame($csTitles, $dsTitles, 'The priority order must differ between specializations.');
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

    public function test_search_still_works_with_the_priority_ordering(): void
    {
        $cs = Specialization::where('name', 'Computer Science')->firstOrFail();

        $response = $this->getJson(
            '/api/v1/career-roles?specialization_id='.$cs->id.'&search=engineer&per_page=100'
        )->assertOk();

        $titles = array_column($response->json('data.data'), 'title');

        $this->assertNotEmpty($titles);

        foreach ($titles as $title) {
            $this->assertStringContainsStringIgnoringCase('engineer', $title);
        }

        // The search must not bypass the activation filter: "QA Engineer"
        // matches the term but is deactivated for the MVP.
        $this->assertContains('Software Engineer', $titles);
        $this->assertNotContains('QA Engineer', $titles);
    }

    // ------------------------------------------------ assessment gate

    public function test_a_deactivated_role_cannot_start_a_new_assessment(): void
    {
        $role = CareerRole::where('slug', 'qa-engineer')->firstOrFail();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('career_role_id');
    }

    public function test_an_active_role_can_still_start_an_assessment(): void
    {
        $role = CareerRole::where('slug', 'backend-developer')->firstOrFail();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.career_role_id', $role->id);
    }

    public function test_an_inactive_role_stays_readable_for_history(): void
    {
        $role = CareerRole::where('slug', 'qa-engineer')->firstOrFail();

        $this->getJson('/api/v1/career-roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('data.id', $role->id)
            ->assertJsonPath('data.status', 'approved');

        $this->getJson('/api/v1/career-roles/'.$role->id.'/skills')
            ->assertOk()
            ->assertJsonPath('data.career_role.id', $role->id);
    }

    // ------------------------------------------------ profile gate

    public function test_a_profile_may_keep_its_deactivated_role_but_may_not_pick_a_new_one(): void
    {
        $deactivated = CareerRole::where('slug', 'qa-engineer')->firstOrFail();
        $alsoDeactivated = CareerRole::where('slug', 'it-support-specialist')->firstOrFail();

        $user = $this->learner();
        $user->studentProfile->forceFill(['primary_career_role_id' => $deactivated->id])->save();
        Sanctum::actingAs($user);

        // Re-sending the value the profile already holds is a no-op, not a
        // new selection: a deactivation must not invalidate existing profiles.
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $deactivated->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Choosing a DIFFERENT deactivated role is a new selection and is
        // refused.
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $alsoDeactivated->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_career_role_id');
    }

    // ------------------------------------------------ policy integrity

    /**
     * Every slug in the policy list must be a real role.
     *
     * A typo in config/mvp.php would silently deactivate nothing — the role it
     * was meant to hide would keep appearing — so the config is checked against
     * the catalog rather than trusted. The only exception is an explicitly
     * documented "not in the catalog yet" role.
     */
    public function test_every_configured_inactive_slug_exists_in_the_catalog(): void
    {
        $knownMissing = array_keys((array) config('mvp.missing_career_roles', []));

        foreach (config('mvp.inactive_career_role_slugs') as $slug) {
            if (in_array($slug, $knownMissing, true)) {
                continue;
            }

            $this->assertTrue(
                CareerRole::where('slug', $slug)->exists(),
                "The policy lists [{$slug}], but no such career role exists.",
            );
        }
    }

    public function test_the_design_and_cybersecurity_families_are_offered_to_nobody(): void
    {
        $blocked = [
            // UI/UX and design
            'ui-designer', 'ux-designer', 'product-designer', 'ux-researcher',
            'interaction-designer', 'design-system-designer',
            'game-designer', 'technical-game-designer',
            // Cybersecurity family
            'security-analyst', 'soc-analyst', 'cybersecurity-engineer',
            'security-engineer', 'incident-response-analyst',
            'threat-intelligence-analyst', 'security-consultant',
            'penetration-tester', 'ethical-hacker', 'red-team-operator',
            'vulnerability-analyst', 'security-testing-engineer',
            'digital-forensics-analyst', 'dfir-analyst', 'cybercrime-analyst',
            'incident-response-specialist',
            // Security roles that sit under the network / cloud tracks
            'network-security-engineer', 'cloud-security-engineer',
        ];

        $offeredTitles = $this->allTitles('/api/v1/career-roles?per_page=100');

        foreach ($blocked as $slug) {
            $role = CareerRole::where('slug', $slug)->first();

            $this->assertNotNull($role, "Career role [{$slug}] is missing from the catalog.");
            $this->assertFalse((bool) $role->is_active, "[{$slug}] must not be offered in the MVP.");
            $this->assertNotContains($role->title, $offeredTitles, "[{$slug}] still appears in the learner list.");

            // Deactivation never removes the role's data.
            $this->assertGreaterThan(0, $role->roleSkills()->count(), "[{$slug}] lost its skills.");
        }
    }

    /**
     * The security roles of the network and cloud tracks are out of MVP, while
     * the plain engineering roles of the SAME tracks stay offered.
     *
     * `network-security-engineer` and `cloud-security-engineer` were initially
     * kept because they are curated under Computer Networks / Cloud Computing.
     * The product decision is that cybersecurity is out of the MVP, and a
     * "security engineer" is a security role whatever its domain — so they are
     * deactivated, and this test pins the boundary in both directions: the two
     * security roles must NOT be offered, the four engineering roles named by
     * the MVP priority lists must still be.
     */
    public function test_the_network_and_cloud_security_roles_are_out_but_their_engineering_roles_stay(): void
    {
        $offeredTitles = $this->allTitles('/api/v1/career-roles?per_page=100');

        // Out: security specialisms, whatever track curates them.
        foreach (['network-security-engineer', 'cloud-security-engineer'] as $slug) {
            $role = CareerRole::where('slug', $slug)->firstOrFail();

            $this->assertFalse((bool) $role->is_active, "[{$slug}] must not be offered in the MVP.");
            $this->assertNotContains($role->title, $offeredTitles, "[{$slug}] still appears in the learner list.");

            // Deactivated, not deleted: skills, questions and pivot links stay.
            $this->assertGreaterThan(0, $role->roleSkills()->count(), "[{$slug}] lost its skills.");
            $this->assertGreaterThan(
                0,
                $this->questionsForRole($role),
                "[{$slug}] lost its questions.",
            );
            $this->assertGreaterThan(
                0,
                $role->specializations()->count(),
                "[{$slug}] lost its specialization links.",
            );
        }

        // In: the engineering roles of the network and cloud tracks.
        foreach (['network-engineer', 'cloud-engineer', 'devops-engineer', 'site-reliability-engineer'] as $slug) {
            $role = CareerRole::where('slug', $slug)->firstOrFail();

            $this->assertTrue((bool) $role->is_active, "[{$slug}] must stay offered.");
            $this->assertContains($role->title, $offeredTitles, "[{$slug}] is missing from the learner list.");
        }
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

        // Item shape: the activation flag is internal and must NOT leak into
        // the payload the frontend already consumes.
        $item = $response->json('data.data.0');

        $this->assertArrayNotHasKey('is_active', $item);
        /*
         * `specializations` was added deliberately: the role's track lives in
         * the `career_role_specialization` pivot, and without it in the payload
         * the frontend had nothing to group by and filed every role under
         * "other". It is an ADDITIVE key — every key that was here before is
         * still here, in the same order — so no existing consumer breaks.
         */
        $this->assertSame(
            ['id', 'title', 'slug', 'version', 'status', 'effective_date', 'description', 'created_at', 'updated_at', 'skills_count', 'specializations'],
            array_keys($item),
        );
    }

    /**
     * The other half of the same report: the roles came back with a null
     * description and no specialization, so the picker had nothing to show
     * under the title and grouped everything as "other".
     *
     * Both are data problems, not shape problems, so this walks every listed
     * role instead of only checking the first one.
     */
    public function test_every_listed_role_carries_a_description_and_a_specialization(): void
    {
        $roles = $this->getJson('/api/v1/career-roles?per_page=100')->assertOk()->json('data.data');

        $this->assertNotEmpty($roles);

        foreach ($roles as $role) {
            $this->assertNotEmpty(
                $role['description'],
                "Career role [{$role['slug']}] must have a description.",
            );

            $this->assertNotEmpty(
                $role['specializations'],
                "Career role [{$role['slug']}] must belong to at least one specialization.",
            );
        }
    }

    // ------------------------------------------------ helpers

    /**
     * @return list<string>
     */
    private function allTitles(string $url): array
    {
        $titles = [];
        $page = 1;

        do {
            $payload = $this->getJson($url.(str_contains($url, '?') ? '&' : '?').'page='.$page)
                ->assertOk()
                ->json('data');

            $titles = array_merge($titles, array_column($payload['data'] ?? [], 'title'));
            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $titles;
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
