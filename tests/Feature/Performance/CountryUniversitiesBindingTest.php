<?php

namespace Tests\Feature\Performance;

use App\Models\Country;
use App\Models\University;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/reference/countries/{country}/universities` used to take `Country $country`
 * and rely on route-model binding. Binding runs a database query *before* the
 * controller, so the endpoint paid a full round trip even when the list it
 * returned was already cached: 1.33 s on a cache HIT against 0.43 s for a list
 * that needed no binding. The database is on another provider, so that round
 * trip is roughly 0.7 s.
 *
 * The id is now validated against the cached country list instead. These tests
 * pin the two things that could go wrong with that change:
 *
 *   1. the cache hit really does reach zero queries — otherwise nothing was
 *      gained;
 *   2. an unknown or non-numeric id still returns 404, and never a 500. An `int`
 *      type hint would have thrown a TypeError on "abc".
 */
class CountryUniversitiesBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function country(string $name, string $iso2): Country
    {
        return Country::forceCreate([
            'name' => $name,
            'name_ar' => $name,
            'iso2' => $iso2,
            'iso3' => strtoupper($iso2).'X',
        ]);
    }

    private function warm(Country $country): void
    {
        $this->getJson("/api/v1/reference/countries/{$country->id}/universities")->assertOk();
        $this->getJson('/api/v1/reference/countries')->assertOk();
    }

    public function test_a_cache_hit_reaches_the_database_zero_times(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        University::forceCreate(['name' => 'Cairo University', 'country_id' => $egypt->id, 'is_active' => true]);

        $this->warm($egypt);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cairo University']);

        $this->assertSame(
            [],
            DB::getQueryLog(),
            'A cached country list must not cost a database round trip — that was the whole point of dropping route-model binding.',
        );
    }

    public function test_an_unknown_country_still_returns_404(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        $this->warm($egypt);

        $this->getJson('/api/v1/reference/countries/999999/universities')
            ->assertNotFound();
    }

    public function test_a_non_numeric_country_still_returns_404_and_not_a_500(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        $this->warm($egypt);

        $this->getJson('/api/v1/reference/countries/abc/universities')
            ->assertNotFound();
    }

    public function test_the_payload_contract_is_unchanged(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        University::forceCreate(['name' => 'Cairo University', 'country_id' => $egypt->id, 'is_active' => true]);
        University::forceCreate(['name' => 'Hidden University', 'country_id' => $egypt->id, 'is_active' => false]);

        $response = $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")->assertOk();

        $response->assertJsonStructure(['success', 'message', 'data' => [['id', 'name']]]);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
        $this->assertSame(['Cairo University'], array_column($response->json('data'), 'name'));
    }

    public function test_a_country_with_no_universities_returns_an_empty_list(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        $jordan = $this->country('Jordan', 'JO');
        University::forceCreate(['name' => 'Cairo University', 'country_id' => $egypt->id, 'is_active' => true]);

        $this->getJson("/api/v1/reference/countries/{$jordan->id}/universities")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }
}
