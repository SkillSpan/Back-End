<?php

namespace Tests\Feature\Reference;

use App\Models\Specialization;
use App\Support\ReferenceCache;
use Database\Seeders\CareerRoleSeeder;
use Database\Seeders\CareerRoleSpecializationSeeder;
use Database\Seeders\MvpCatalogSeeder;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpSpecializationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            SpecializationsSeeder::class,
            SkillSeeder::class,
            CareerRoleSeeder::class,
            CareerRoleSpecializationSeeder::class,
            MvpCatalogSeeder::class,
        ]);
    }

    public function test_the_reference_endpoint_exposes_exactly_the_mvp_tracks(): void
    {
        $names = array_column(
            $this->getJson('/api/v1/reference/specializations')->assertOk()->json('data'),
            'name',
        );

        sort($names);

        $expected = config('mvp.specializations');
        $expected[] = config('mvp.free_track');
        sort($expected);

        $this->assertSame($expected, $names);
        $this->assertCount(9, $names);
    }

    public function test_each_mvp_track_is_active_and_the_free_track_is_flagged(): void
    {
        foreach (config('mvp.specializations') as $name) {
            $specialization = Specialization::where('name', $name)->first();

            $this->assertNotNull($specialization, "MVP specialization [{$name}] is missing.");
            $this->assertTrue((bool) $specialization->is_active, "[{$name}] must be active.");
            $this->assertFalse($specialization->isFreeTrack(), "[{$name}] must not be the free track.");
        }

        $free = Specialization::where('name', config('mvp.free_track'))->first();

        $this->assertNotNull($free);
        $this->assertTrue((bool) $free->is_active);
        $this->assertTrue($free->isFreeTrack());
    }

    public function test_out_of_scope_tracks_are_deactivated_but_never_deleted(): void
    {
        $legacy = Specialization::where('name', 'Cybersecurity')->first();

        $this->assertNotNull($legacy, 'A deactivated specialization must stay in the database.');
        $this->assertFalse((bool) $legacy->is_active);

        foreach (['Nursing', 'Law', 'Accounting'] as $name) {
            $row = Specialization::where('name', $name)->first();

            $this->assertNotNull($row, "[{$name}] must not be deleted.");
            $this->assertFalse((bool) $row->is_active);
        }
    }

    public function test_the_deactivated_tracks_keep_their_career_role_links(): void
    {
        $legacy = Specialization::where('name', 'Computer Networks')->firstOrFail();

        $this->assertFalse((bool) $legacy->is_active);
        $this->assertGreaterThan(
            0,
            $legacy->careerRoles()->count(),
            'Deactivating a specialization must not detach its pivot rows.',
        );
    }

    public function test_the_merged_networks_and_cloud_track_exists_once(): void
    {
        $rows = Specialization::where('name', 'Networks & Cloud Computing')->get();

        $this->assertCount(1, $rows);
        $this->assertTrue((bool) $rows->first()->is_active);

        $this->assertGreaterThan(0, $rows->first()->careerRoles()->count());
    }

    public function test_no_duplicate_specialization_names_exist(): void
    {
        $duplicates = Specialization::query()
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name')
            ->all();

        $this->assertSame([], $duplicates);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $specializationCount = Specialization::count();
        $activeCount = Specialization::where('is_active', true)->count();

        $this->seed(MvpCatalogSeeder::class);
        $this->seed(MvpCatalogSeeder::class);

        $this->assertSame($specializationCount, Specialization::count());
        $this->assertSame($activeCount, Specialization::where('is_active', true)->count());
    }

    public function test_applying_the_policy_invalidates_the_cached_track_list(): void
    {
        $legacy = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $legacy->update(['is_active' => true]);

        cache()->forget(ReferenceCache::SPECIALIZATIONS);

        $before = array_column(
            $this->getJson('/api/v1/reference/specializations')->assertOk()->json('data'),
            'name',
        );

        $this->assertContains('Cybersecurity', $before);

        $this->seed(MvpCatalogSeeder::class);

        $after = array_column(
            $this->getJson('/api/v1/reference/specializations')->assertOk()->json('data'),
            'name',
        );

        $this->assertNotContains('Cybersecurity', $after);
        $this->assertContains(config('mvp.free_track'), $after);
    }

    public function test_the_seeder_can_reactivate_a_track_without_recreating_it(): void
    {
        $legacy = Specialization::where('name', 'Cybersecurity')->firstOrFail();
        $originalId = $legacy->id;

        $legacy->update(['is_active' => true]);

        $this->seed(MvpCatalogSeeder::class);

        $reloaded = Specialization::where('name', 'Cybersecurity')->firstOrFail();

        $this->assertFalse((bool) $reloaded->is_active);
        $this->assertSame($originalId, $reloaded->id);
        $this->assertSame(1, Specialization::where('name', 'Cybersecurity')->count());
    }
}
