<?php

namespace Tests\Feature\CareerRoles;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CareerRoleSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);
    }

    public function test_search_finds_a_role_that_lives_on_a_later_page(): void
    {
        $target = $this->role('Data Analyst');
        foreach (range(1, 20) as $i) {
            $this->role(sprintf('Placeholder Role %02d', $i));
        }

        $pageOne = array_column(
            $this->getJson('/api/v1/career-roles?page=1&per_page=15')->json('data.data'),
            'title',
        );
        $this->assertNotContains('Data Analyst', $pageOne);

        $response = $this->getJson('/api/v1/career-roles?search='.urlencode('Data Analyst').'&page=1&per_page=15')
            ->assertOk()
            ->assertJsonPath('success', true);

        $titles = array_column($response->json('data.data'), 'title');

        $this->assertSame(['Data Analyst'], $titles);
        $this->assertSame($target->id, $response->json('data.data.0.id'));
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_search_is_a_partial_match_and_case_insensitive(): void
    {
        $this->role('Data Analyst');
        $this->role('Backend Developer');

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?search=analyst')->json('data.data'),
            'title',
        );

        $this->assertSame(['Data Analyst'], $titles);
    }

    public function test_search_returns_an_empty_page_when_nothing_matches(): void
    {
        $this->role('Data Analyst');

        $response = $this->getJson('/api/v1/career-roles?search=Nuclear%20Physicist')->assertOk();

        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));
    }

    public function test_a_blank_search_does_not_filter_anything(): void
    {
        $this->role('Data Analyst');
        $this->role('Backend Developer');

        $this->assertSame(2, $this->getJson('/api/v1/career-roles?search=')->json('data.total'));
        $this->assertSame(2, $this->getJson('/api/v1/career-roles?search=%20%20')->json('data.total'));
        $this->assertSame(2, $this->getJson('/api/v1/career-roles')->json('data.total'));
    }

    public function test_search_never_returns_a_role_that_is_not_approved(): void
    {
        $this->role('Data Analyst');
        $this->role('Data Analyst Draft', 'draft');

        $titles = array_column(
            $this->getJson('/api/v1/career-roles?search=Data%20Analyst')->json('data.data'),
            'title',
        );

        $this->assertSame(['Data Analyst'], $titles);
    }

    public function test_wildcards_in_the_search_term_are_treated_literally(): void
    {
        $this->role('Data Analyst');
        $this->role('Backend Developer');

        $response = $this->getJson('/api/v1/career-roles?search=%25')->assertOk();
        $this->assertSame(0, $response->json('data.total'));
    }

    public function test_a_search_term_longer_than_the_limit_is_rejected(): void
    {
        $this->getJson('/api/v1/career-roles?search='.str_repeat('a', 121))
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');
    }

    private function role(string $title, string $status = 'approved'): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }
}
