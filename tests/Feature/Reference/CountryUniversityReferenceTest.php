<?php

namespace Tests\Feature\Reference;

use App\Models\Country;
use App\Models\University;
use Database\Seeders\CountrySeeder;
use Database\Seeders\UniversitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * countries → universities reference data (countries task):
 * the public country list, the country-filtered university list, the
 * 404 for an unknown country, the Eloquent relationship, and seeder
 * idempotency (the university import must never duplicate rows).
 */
class CountryUniversityReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function country(): Country
    {
        return Country::create([
            'name' => 'Palestine',
            'name_ar' => 'فلسطين',
            'iso2' => 'PS',
            'iso3' => 'PSE',
        ]);
    }

    public function test_countries_are_listed_with_reference_fields(): void
    {
        $this->country();

        Country::create([
            'name' => 'Jordan',
            'name_ar' => 'الأردن',
            'iso2' => 'JO',
            'iso3' => 'JOR',
        ]);

        $response = $this->getJson('/api/v1/reference/countries');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'name_ar', 'iso2', 'iso3'],
                ],
            ]);

        // Stable alphabetical ordering by English name.
        $this->assertSame(
            ['Jordan', 'Palestine'],
            array_column($response->json('data'), 'name')
        );
    }

    public function test_country_universities_return_only_that_country(): void
    {
        $palestine = $this->country();

        $jordan = Country::create([
            'name' => 'Jordan',
            'name_ar' => 'الأردن',
            'iso2' => 'JO',
            'iso3' => 'JOR',
        ]);

        University::create(['name' => 'Birzeit University', 'country_id' => $palestine->id, 'is_active' => true]);
        University::create(['name' => 'An-Najah National University', 'country_id' => $palestine->id, 'is_active' => true]);
        University::create(['name' => 'Retired University', 'country_id' => $palestine->id, 'is_active' => false]);
        University::create(['name' => 'University of Jordan', 'country_id' => $jordan->id, 'is_active' => true]);

        $response = $this->getJson("/api/v1/reference/countries/{$palestine->id}/universities");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');

        $this->assertSame(['An-Najah National University', 'Birzeit University'], $names);
        $this->assertNotContains('University of Jordan', $names);
        $this->assertNotContains('Retired University', $names);
    }

    public function test_unknown_country_returns_404(): void
    {
        $this->getJson('/api/v1/reference/countries/999/universities')
            ->assertStatus(404);
    }

    public function test_university_belongs_to_country_and_country_has_many_universities(): void
    {
        $palestine = $this->country();

        $birzeit = University::create([
            'name' => 'Birzeit University',
            'country_id' => $palestine->id,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Country::class, $birzeit->country);
        $this->assertSame($palestine->id, $birzeit->country->id);

        $this->assertCount(1, $palestine->universities);
        $this->assertSame('Birzeit University', $palestine->universities->first()->name);
    }

    public function test_same_university_name_is_allowed_in_different_countries(): void
    {
        // Hipolabs contains repeated names across countries; the schema
        // must accept them (UNIQUE(country_id, name), not UNIQUE(name)).
        $palestine = $this->country();

        $jordan = Country::create([
            'name' => 'Jordan',
            'name_ar' => 'الأردن',
            'iso2' => 'JO',
            'iso3' => 'JOR',
        ]);

        University::create(['name' => 'Arab Open University', 'country_id' => $palestine->id, 'is_active' => true]);
        University::create(['name' => 'Arab Open University', 'country_id' => $jordan->id, 'is_active' => true]);

        $this->assertDatabaseCount('universities', 2);
    }

    public function test_country_seeder_is_idempotent(): void
    {
        $this->seed(CountrySeeder::class);
        $firstCount = Country::count();

        $this->assertGreaterThan(0, $firstCount);

        $this->seed(CountrySeeder::class);

        $this->assertSame($firstCount, Country::count());

        // Palestine (PS) is present with its Arabic name from the snapshot.
        $this->assertDatabaseHas('countries', [
            'iso2' => 'PS',
            'name' => 'Palestine',
        ]);
    }

    public function test_university_seeder_is_idempotent(): void
    {
        $this->seed(CountrySeeder::class);

        // The committed snapshot is used when present — no HTTP involved.
        $this->seed(UniversitySeeder::class);
        $firstCount = University::count();

        $this->assertGreaterThan(0, $firstCount);

        $this->seed(UniversitySeeder::class);

        $this->assertSame($firstCount, University::count());
    }

    public function test_university_seeder_handles_hipolabs_failures_gracefully(): void
    {
        // Snapshot missing + network down: the seeder warns and returns
        // without inserting anything or throwing.
        config(['database.data.snapshot_path' => null]);

        $snapshotPath = database_path('data/universities.json');
        $backup = $snapshotPath.'.test-backup';

        $this->assertTrue(copy($snapshotPath, $backup) || ! file_exists($snapshotPath));

        if (file_exists($snapshotPath)) {
            unlink($snapshotPath);
        }

        Http::fake(function () {
            return Http::response(null, 500);
        });

        try {
            $this->seed(CountrySeeder::class);
            $this->seed(UniversitySeeder::class);

            $this->assertSame(0, University::count());
            $this->assertGreaterThan(0, Country::count());
        } finally {
            // Never leave the snapshot deleted, even on failure.
            if (file_exists($backup) && ! file_exists($snapshotPath)) {
                rename($backup, $snapshotPath);
            } elseif (file_exists($backup)) {
                unlink($backup);
            }
        }
    }
}
