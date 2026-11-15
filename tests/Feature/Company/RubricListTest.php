<?php

namespace Tests\Feature\Company;

use App\Models\Role;
use App\Models\Rubric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RubricListTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        $role = Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);

        $user = User::factory()->create();
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function rubric(string $title, string $status, int $version = 1): Rubric
    {
        $rubric = Rubric::create([
            'title' => $title,
            'version' => $version,
        ]);
        $rubric->forceFill([
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ])->save();

        return $rubric;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/rubrics')->assertStatus(401);
    }

    public function test_a_learner_cannot_read_rubrics(): void
    {
        Sanctum::actingAs($this->userWithRole('learner'));

        $this->getJson('/api/v1/rubrics')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_ADMIN_ONLY');
    }

    public function test_a_company_admin_gets_only_the_published_ones(): void
    {
        $this->rubric('UX case study rubric', 'published', 2);
        $this->rubric('Software project rubric', 'published', 3);
        $this->rubric('Draft rubric', 'draft');
        $this->rubric('Retired rubric', 'retired');

        Sanctum::actingAs($this->userWithRole('company_admin'));

        $response = $this->getJson('/api/v1/rubrics')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $titles = array_column($response->json('data'), 'name');
        $this->assertContains('UX case study rubric', $titles);
        $this->assertContains('Software project rubric', $titles);
        $this->assertNotContains('Draft rubric', $titles);
        $this->assertNotContains('Retired rubric', $titles);
    }

    public function test_the_company_facing_status_is_approved(): void
    {
        $this->rubric('UX case study rubric', 'published');

        Sanctum::actingAs($this->userWithRole('company_admin'));

        $this->getJson('/api/v1/rubrics')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'approved');
    }

    public function test_the_payload_carries_what_the_picker_needs(): void
    {
        $rubric = $this->rubric('UX case study rubric', 'published', 2);

        Sanctum::actingAs($this->userWithRole('company_admin'));

        $this->getJson('/api/v1/rubrics')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $rubric->id)
            ->assertJsonPath('data.0.name', 'UX case study rubric')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'title', 'version', 'status', 'criteria_count']]]);
    }
}
