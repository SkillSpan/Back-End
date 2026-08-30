<?php

namespace Tests\Feature\Reference;

use App\Models\Specialization;
use App\Models\University;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_can_list_universities(): void
    {
        University::create(['name' => 'An-Najah National University', 'is_active' => true]);
        University::create(['name' => 'Birzeit University', 'is_active' => true]);
        University::create(['name' => 'Retired University', 'is_active' => false]);

        $response = $this->getJson('/api/v1/reference/universities');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_unauthenticated_user_can_list_specializations(): void
    {
        Specialization::create(['name' => 'Computer Science', 'is_active' => true]);
        Specialization::create(['name' => 'Retired Spec', 'is_active' => false]);

        $response = $this->getJson('/api/v1/reference/specializations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_inactive_reference_records_are_excluded(): void
    {
        University::create(['name' => 'Active University', 'is_active' => true]);
        University::create(['name' => 'Inactive University', 'is_active' => false]);

        $this->getJson('/api/v1/reference/universities')
            ->assertStatus(200)
            ->assertJsonMissing(['name' => 'Inactive University']);
    }
}
