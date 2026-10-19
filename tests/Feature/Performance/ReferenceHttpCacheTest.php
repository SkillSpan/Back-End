<?php

namespace Tests\Feature\Performance;

use App\Models\Country;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\University;
use App\Models\User;
use App\Support\ReferenceCache;
use App\Support\ReferenceResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reference lists are 541 KB at their largest and identical for every
 * caller, yet Laravel answered them with `Cache-Control: no-cache, private`, so
 * a browser downloaded them again on every page load.
 *
 * These tests pin the HTTP cache contract:
 *
 *   1. the public dropdowns advertise a shared cache and carry an ETag;
 *   2. a matching If-None-Match returns 304 with no body — the whole point of
 *      the change, because that is what a repeat visit costs;
 *   3. a stale ETag still returns the full payload, so the 304 can never
 *      swallow a real change;
 *   4. the authenticated taxonomy is `private`, never `public`, because it sits
 *      behind a token and a shared cache must not hand it to a guest.
 */
class ReferenceHttpCacheTest extends TestCase
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

    private function etag(TestResponse $response): string
    {
        $etag = $response->headers->get('ETag');

        $this->assertNotNull($etag, 'The response must carry an ETag so a repeat visit can revalidate.');

        return $etag;
    }

    // ---------------------------------------------------------------------
    // Shared caching on the public dropdowns
    // ---------------------------------------------------------------------

    public function test_the_public_dropdowns_advertise_a_shared_cache(): void
    {
        Specialization::forceCreate(['name' => 'Software Engineering', 'is_active' => true]);
        $this->country('Egypt', 'EG');
        University::forceCreate(['name' => 'Cairo University', 'is_active' => true]);

        $endpoints = [
            '/api/v1/reference/universities',
            '/api/v1/reference/specializations',
            '/api/v1/reference/countries',
        ];

        foreach ($endpoints as $endpoint) {
            $cacheControl = $this->getJson($endpoint)->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('public', $cacheControl, $endpoint);
            $this->assertStringContainsString('max-age='.ReferenceResponse::MAX_AGE_SECONDS, $cacheControl, $endpoint);
            $this->assertStringNotContainsString('no-cache', $cacheControl, $endpoint);
        }
    }

    public function test_a_matching_etag_returns_304_without_a_body(): void
    {
        University::forceCreate(['name' => 'Cairo University', 'is_active' => true]);

        $first = $this->getJson('/api/v1/reference/universities')->assertOk();
        $etag = $this->etag($first);

        $second = $this->getJson('/api/v1/reference/universities', ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), 'A 304 must carry no body.');
    }

    public function test_a_stale_etag_still_returns_the_full_payload(): void
    {
        University::forceCreate(['name' => 'Cairo University', 'is_active' => true]);

        $this->getJson('/api/v1/reference/universities', ['If-None-Match' => '"stale-etag"'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cairo University']);
    }

    public function test_the_etag_changes_when_the_reference_data_changes(): void
    {
        University::forceCreate(['name' => 'Cairo University', 'is_active' => true]);
        $before = $this->etag($this->getJson('/api/v1/reference/universities')->assertOk());

        // Simulate a redeploy whose seeder added a row, then a revalidation.
        University::forceCreate(['name' => 'Ain Shams University', 'is_active' => true]);
        Cache::forget(ReferenceCache::UNIVERSITIES);

        $after = $this->etag($this->getJson('/api/v1/reference/universities')->assertOk());

        $this->assertNotSame($before, $after, 'A changed payload must produce a different ETag.');
    }

    public function test_country_universities_are_cacheable_per_country(): void
    {
        $egypt = $this->country('Egypt', 'EG');
        $jordan = $this->country('Jordan', 'JO');

        University::forceCreate(['name' => 'Cairo University', 'country_id' => $egypt->id, 'is_active' => true]);
        University::forceCreate(['name' => 'Jordan University', 'country_id' => $jordan->id, 'is_active' => true]);

        $egyptResponse = $this->getJson("/api/v1/reference/countries/{$egypt->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cairo University']);

        $jordanResponse = $this->getJson("/api/v1/reference/countries/{$jordan->id}/universities")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Jordan University']);

        $this->assertStringContainsString(
            'public',
            $egyptResponse->headers->get('Cache-Control'),
        );

        $this->assertNotSame(
            $this->etag($egyptResponse),
            $this->etag($jordanResponse),
            'Each country must get its own ETag, or one country would be served for another.',
        );

        $this->getJson(
            "/api/v1/reference/countries/{$egypt->id}/universities",
            ['If-None-Match' => $this->etag($egyptResponse)],
        )->assertStatus(304);
    }

    // ---------------------------------------------------------------------
    // The authenticated taxonomy must stay private
    // ---------------------------------------------------------------------

    public function test_the_skills_taxonomy_is_private_and_never_public(): void
    {
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Learner',
            'email' => 'taxonomy-http-cache@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]));

        Skill::forceCreate(['name' => 'PHP', 'slug' => 'php', 'status' => 'active']);

        $response = $this->getJson('/api/v1/skills/taxonomy')->assertOk();
        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age='.ReferenceResponse::MAX_AGE_SECONDS, $cacheControl);

        $this->getJson('/api/v1/skills/taxonomy', ['If-None-Match' => $this->etag($response)])
            ->assertStatus(304);
    }
}
