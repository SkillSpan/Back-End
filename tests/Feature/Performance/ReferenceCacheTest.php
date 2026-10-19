<?php

namespace Tests\Feature\Performance;

use App\Models\Country;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\University;
use App\Models\User;
use App\Support\ReferenceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reference endpoints (university / specialization / country dropdowns and
 * the skills taxonomy) are cached because the database sits on a different
 * provider from the application, so every round trip costs roughly a second.
 *
 * These tests pin two things at once:
 *
 *   1. the cache actually short-circuits the query — a row inserted after the
 *      first call must NOT appear on the second, and must appear again once the
 *      key is dropped;
 *   2. the response contract is untouched — same envelope, same columns. The
 *      cache stores plain arrays, so a change that accidentally starts caching
 *      Eloquent models (with their casts and appended attributes) would show up
 *      here as extra keys.
 */
class ReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function university(string $name, ?int $countryId = null, bool $active = true): University
    {
        return University::forceCreate([
            'name' => $name,
            'country_id' => $countryId,
            'is_active' => $active,
        ]);
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

    // ---------------------------------------------------------------------
    // Universities
    // ---------------------------------------------------------------------

    public function test_the_universities_list_is_served_from_cache_on_the_second_call(): void
    {
        $this->university('Alpha University');

        $this->getJson('/api/v1/reference/universities')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Alpha University']);

        $this->university('Zeta University');

        // Still the cached list: the row added a moment ago is not there yet.
        $this->getJson('/api/v1/reference/universities')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Zeta University']);

        Cache::forget(ReferenceCache::UNIVERSITIES);

        $this->getJson('/api/v1/reference/universities')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Zeta University']);
    }

    public function test_the_universities_response_contract_is_unchanged(): void
    {
        $this->university('Alpha University');

        $response = $this->getJson('/api/v1/reference/universities')->assertOk();

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'name']],
        ]);

        $this->assertSame(
            ['id', 'name'],
            array_keys($response->json('data.0')),
            'The cached payload must expose exactly the columns the endpoint always returned.',
        );
    }

    public function test_inactive_universities_are_excluded_and_the_list_stays_alphabetical(): void
    {
        $this->university('Zeta University');
        $this->university('Alpha University');
        $this->university('Hidden University', null, false);

        $names = array_column($this->getJson('/api/v1/reference/universities')->json('data'), 'name');

        $this->assertSame(['Alpha University', 'Zeta University'], $names);
    }

    // ---------------------------------------------------------------------
    // Specializations
    // ---------------------------------------------------------------------

    public function test_the_specializations_list_is_cached(): void
    {
        Specialization::forceCreate(['name' => 'Software Engineering', 'is_active' => true]);

        $this->getJson('/api/v1/reference/specializations')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Software Engineering']);

        Specialization::forceCreate(['name' => 'Data Science', 'is_active' => true]);

        $this->getJson('/api/v1/reference/specializations')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Data Science']);

        Cache::forget(ReferenceCache::SPECIALIZATIONS);

        $this->getJson('/api/v1/reference/specializations')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Data Science']);
    }

    // ---------------------------------------------------------------------
    // Countries
    // ---------------------------------------------------------------------

    public function test_the_countries_list_is_cached(): void
    {
        $this->country('Egypt', 'EG');

        $this->getJson('/api/v1/reference/countries')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Egypt']);

        $this->country('Jordan', 'JO');

        $this->getJson('/api/v1/reference/countries')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Jordan']);

        Cache::forget(ReferenceCache::COUNTRIES);

        $this->getJson('/api/v1/reference/countries')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Jordan']);
    }

    public function test_country_universities_are_cached_per_country(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        $jordan = $this->country('Jordan', 'JO');

        $this->university('Cairo University', $egypt->id);
        $this->university('Jordan University', $jordan->id);

        $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cairo University'])
            ->assertJsonMissing(['name' => 'Jordan University']);

        // A new row for Egypt does not leak into the cached response ...
        $this->university('Ain Shams University', $egypt->id);

        $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")
            ->assertOk()
            ->assertJsonMissing(['name' => 'Ain Shams University']);

        // ... and dropping only Egypt's key leaves Jordan's cache intact.
        Cache::forget(ReferenceCache::countryUniversities($egypt->id));

        $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ain Shams University']);

        $this->getJson("/api/v1/reference/countries/{$jordan->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Jordan University']);
    }

    // ---------------------------------------------------------------------
    // Skills taxonomy
    // ---------------------------------------------------------------------

    public function test_the_skills_taxonomy_is_cached(): void
    {
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Learner',
            'email' => 'taxonomy-cache@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]));

        Skill::forceCreate(['name' => 'PHP', 'slug' => 'php', 'status' => 'active']);

        $this->getJson('/api/v1/skills/taxonomy')
            ->assertOk()
            ->assertJsonFragment(['name' => 'PHP']);

        Skill::forceCreate(['name' => 'Rust', 'slug' => 'rust', 'status' => 'active']);

        $this->getJson('/api/v1/skills/taxonomy')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Rust']);

        Cache::forget(ReferenceCache::SKILLS_TAXONOMY);

        $this->getJson('/api/v1/skills/taxonomy')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Rust']);
    }
}
