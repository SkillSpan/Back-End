<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin specialization writes must be visible on the public reference list
 * immediately.
 *
 * `GET /api/v1/reference/specializations` is cached for twelve hours — the
 * database is remote, so the round trip dominates the endpoint. The cache is
 * only correct as long as every write invalidates it; without that, an operator
 * creates / renames / activates / deactivates a track and the learner-facing
 * dropdown keeps serving the previous list for the rest of the TTL.
 *
 * Each test therefore PRIMES the cache with a real request first, mutates
 * through the admin API, and then asserts the next public request already shows
 * the change — which can only pass if the write flushed the cache.
 */
class AdminSpecializationCacheTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    public function test_a_created_specialization_appears_immediately(): void
    {
        $this->assertNotContains('Robotics Engineering', $this->publicNames());

        $this->actingAs($this->admin())
            ->postJson('/admin/api/specializations', ['name' => 'Robotics Engineering'])
            ->assertStatus(201);

        $this->assertContains('Robotics Engineering', $this->publicNames());
    }

    public function test_a_renamed_specialization_appears_immediately(): void
    {
        $specialization = $this->specialization('Old Name Track');

        $this->assertContains('Old Name Track', $this->publicNames());

        $this->actingAs($this->admin())
            ->patchJson('/admin/api/specializations/'.$specialization->id, ['name' => 'New Name Track'])
            ->assertOk();

        $names = $this->publicNames();

        $this->assertContains('New Name Track', $names);
        $this->assertNotContains('Old Name Track', $names);
    }

    public function test_a_deactivated_specialization_disappears_immediately(): void
    {
        $specialization = $this->specialization('Cybersecurity');

        $this->assertContains('Cybersecurity', $this->publicNames());

        $this->actingAs($this->admin())
            ->postJson('/admin/api/specializations/'.$specialization->id.'/deactivate')
            ->assertOk();

        $this->assertNotContains('Cybersecurity', $this->publicNames());
    }

    public function test_a_reactivated_specialization_reappears_immediately(): void
    {
        $specialization = $this->specialization('Cybersecurity', isActive: false);

        $this->assertNotContains('Cybersecurity', $this->publicNames());

        $this->actingAs($this->admin())
            ->postJson('/admin/api/specializations/'.$specialization->id.'/activate')
            ->assertOk();

        $this->assertContains('Cybersecurity', $this->publicNames());
    }

    public function test_a_deleted_specialization_disappears_immediately(): void
    {
        $specialization = $this->specialization('Temporary Track');

        $this->assertContains('Temporary Track', $this->publicNames());

        $this->actingAs($this->admin())
            ->deleteJson('/admin/api/specializations/'.$specialization->id)
            ->assertOk();

        $this->assertNotContains('Temporary Track', $this->publicNames());
    }

    public function test_an_edit_that_only_changes_the_description_also_refreshes_the_list(): void
    {
        $specialization = $this->specialization('Data Track');

        $this->assertContains('Data Track', $this->publicNames());

        $this->actingAs($this->admin())
            ->patchJson('/admin/api/specializations/'.$specialization->id, [
                'name' => 'Data Track',
                'description' => 'Updated description.',
            ])
            ->assertOk();

        // The list is rebuilt from the database rather than served from the
        // copy primed above.
        $this->assertContains('Data Track', $this->publicNames());
        $this->assertSame('Updated description.', $specialization->fresh()->description);
    }

    public function test_the_admin_list_is_never_served_from_the_public_cache(): void
    {
        $this->publicNames(); // prime

        $this->actingAs($this->admin());
        $this->specialization('Hidden Track', isActive: false);

        $adminNames = array_column(
            $this->getJson('/admin/api/specializations?per_page=100')->assertOk()->json('data.data'),
            'name',
        );

        $this->assertContains('Hidden Track', $adminNames, 'The admin list must show inactive rows too.');
        $this->assertNotContains('Hidden Track', $this->publicNames());
    }

    // ------------------------------------------------ helpers

    /**
     * The public (cached) specialization list, read exactly as a learner's
     * browser would.
     *
     * @return list<string>
     */
    private function publicNames(): array
    {
        return array_column(
            $this->getJson('/api/v1/reference/specializations')->assertOk()->json('data'),
            'name',
        );
    }

    private function specialization(string $name, bool $isActive = true): Specialization
    {
        return Specialization::create(['name' => $name, 'is_active' => $isActive]);
    }

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => uniqid().'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->adminRole->id);

        return $user;
    }
}
