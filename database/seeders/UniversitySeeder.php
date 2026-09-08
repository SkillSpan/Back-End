<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\University;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * University reference data (countries → universities task).
 *
 * Source: http://universities.hipolabs.com — fetched ONCE into the local
 * snapshot database/data/universities.json (name + country_code, already
 * matched against countries.iso2). The frontend never talks to Hipolabs.
 *
 * If the snapshot is absent, the seeder downloads it once (Hipolabs
 * search endpoint), persists it, and proceeds — so production seeds from
 * the committed snapshot and never needs the API at runtime. Every skip
 * (malformed record, unmatched country code, network failure) degrades
 * gracefully: the row is skipped and counted, the seeder never aborts.
 *
 * Idempotency: (country_id, name) is the uniqueness key in the schema, so
 * re-runs upsert on that pair and cannot duplicate rows.
 *
 * PERFORMANCE: this used to call University::updateOrCreate() once per
 * record inside a foreach loop — one round trip per row. Against a
 * remote database (Render) with 10,000+ rows, that took upwards of an
 * hour and made the reference-data endpoints look permanently empty
 * while it was still running. Batched upsert() does the same "insert or
 * update on conflict" semantics in a handful of queries instead of one
 * per row.
 */
class UniversitySeeder extends Seeder
{
    private const SNAPSHOT_PATH = 'database/data/universities.json';

    private const HIPOLABS_URL = 'http://universities.hipolabs.com/search?name=';

    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $records = $this->loadSnapshot();

        if ($records === null) {
            return;
        }

        $countriesByIso2 = Country::query()->pluck('id', 'iso2')->all();

        if ($countriesByIso2 === []) {
            $this->command?->error('No countries found — run CountrySeeder first.');

            return;
        }

        $rows = [];
        $skippedMalformed = 0;
        $skippedUnmatched = 0;
        $seen = [];
        $now = now();

        foreach ($records as $record) {
            $name = trim((string) ($record['name'] ?? ''));
            $iso2 = strtoupper(trim((string) ($record['country_code'] ?? '')));

            if ($name === '' || strlen($name) > 191) {
                $skippedMalformed++;

                continue;
            }

            $countryId = $countriesByIso2[$iso2] ?? null;

            // Unknown country code: skip the record, never invent a country.
            if ($countryId === null) {
                $skippedUnmatched++;

                continue;
            }

            $key = $countryId.'|'.mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rows[] = [
                'country_id' => $countryId,
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Cheap "how many already exist" check up front, so we can report
        // created/updated counts without needing per-row wasRecentlyCreated
        // (which upsert() does not give us).
        $beforeCount = University::query()->count();

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            University::query()->upsert(
                $chunk,
                ['country_id', 'name'],
                ['is_active', 'updated_at']
            );
        }

        $afterCount = University::query()->count();
        $created = max(0, $afterCount - $beforeCount);
        $updated = max(0, count($rows) - $created);

        $backfilled = $this->backfillExistingRows($countriesByIso2);

        $this->command?->info(
            "UniversitySeeder: {$created} created, {$updated} updated, "
                ."{$skippedMalformed} malformed, {$skippedUnmatched} unmatched-country, "
                ."{$backfilled} backfilled."
        );
    }

    /**
     * @return array<int, array{name: string, country_code: string}>|null
     */
    private function loadSnapshot(): ?array
    {
        $path = database_path('data/universities.json');

        if (is_readable($path)) {
            $records = json_decode((string) file_get_contents($path), true);

            if (is_array($records)) {
                return $records;
            }

            $this->command?->error('universities.json is not valid JSON.');

            return null;
        }

        return $this->downloadSnapshot($path);
    }

    /**
     * Snapshot missing (fresh clone / first run): fetch Hipolabs once,
     * filter to {name, country_code} against the countries table's iso2
     * codes, and persist for the next run. Network failure → warning +
     * null; the application stays usable without this reference data.
     *
     * @return array<int, array{name: string, country_code: string}>|null
     */
    private function downloadSnapshot(string $path): ?array
    {
        $this->command?->warn('universities.json snapshot missing — downloading from Hipolabs.');

        try {
            $response = Http::timeout(60)->retry(2, 500)->get(self::HIPOLABS_URL);
        } catch (\Throwable $e) {
            $this->command?->error('Hipolabs unreachable: '.$e->getMessage());
            Log::warning('UniversitySeeder: Hipolabs download failed: '.$e->getMessage());

            return null;
        }

        $all = $response->json();

        if (! is_array($all)) {
            $this->command?->error('Hipolabs returned invalid JSON — skipping UniversitySeeder.');

            return null;
        }

        $iso2Set = array_fill_keys(
            Country::query()->pluck('iso2')->all(),
            true
        );

        $records = [];
        $seen = [];

        foreach ($all as $university) {
            $name = trim((string) ($university['name'] ?? ''));
            $code = strtoupper(trim((string) ($university['country_code'] ?? '')));

            if ($name === '' || strlen($name) > 191 || ! isset($iso2Set[$code])) {
                continue;
            }

            $key = $code.'|'.mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $records[] = ['name' => $name, 'country_code' => $code];
        }

        @file_put_contents($path, json_encode(
            $records,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->command?->info('Snapshot persisted: '.count($records).' universities.');

        return $records;
    }

    /**
     * Rows that existed before the country_id column was added (the old
     * fixed Palestinian list) get their country backfilled by matching
     * Hipolabs names against the imported dataset.
     *
     * Kept as a small per-row loop (unlike run()'s bulk upsert above)
     * because in practice this only ever touches a handful of leftover
     * rows (the old hardcoded list), not thousands — batching it would
     * add complexity for no measurable benefit.
     *
     * @param  array<string, int>  $countriesByIso2
     */
    private function backfillExistingRows(array $countriesByIso2): int
    {
        // PS is the country of the old fixed list; the Hipolabs dataset
        // is the source of truth, so backfill only what it confirms.
        $backfill = [];

        foreach (University::whereNull('country_id')->limit(500)->get() as $university) {
            $match = University::where('name', $university->name)
                ->whereNotNull('country_id')
                ->first();

            if ($match) {
                $backfill[$university->id] = $match->country_id;
            }
        }

        $backfilled = 0;
        $conflicts = 0;

        foreach ($backfill as $id => $countryId) {
            try {
                University::whereKey($id)->update(['country_id' => $countryId]);
                $backfilled++;
            } catch (UniqueConstraintViolationException $e) {
                // The null-country_id row is a leftover duplicate: a row
                // with this exact (country_id, name) pair already exists
                // (created earlier in run(), from the Hipolabs import).
                // Nothing to backfill here — the canonical row already
                // exists — so we log and skip instead of crashing the
                // whole seeder over a redundant leftover row.
                $conflicts++;
                Log::warning('UniversitySeeder: skipped duplicate row during backfill', [
                    'university_id' => $id,
                    'attempted_country_id' => $countryId,
                ]);
            }
        }

        if ($conflicts > 0) {
            $this->command?->warn("UniversitySeeder: {$conflicts} duplicate row(s) skipped during backfill.");
        }

        return $backfilled;
    }
}
