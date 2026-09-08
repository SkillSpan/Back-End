<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Country reference data. Reads the locally stored, pre-filtered snapshot
 * of github.com/mledoze/countries (database/data/countries.json — name,
 * name_ar, iso2, iso3 only). iso2 is the external identifier, so re-runs
 * update in place instead of duplicating rows.
 *
 * PERFORMANCE: this used to call Country::updateOrCreate() once per
 * record inside a foreach loop — one round trip per row. Against a
 * remote database (Render), that turned 250 rows into 250 sequential
 * network round trips (~70s in production). upsert() does the same
 * "insert or update on conflict" in a handful of batched queries instead.
 */
class CountrySeeder extends Seeder
{
    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $path = database_path('data/countries.json');

        if (! is_readable($path)) {
            $this->command?->warn('countries.json snapshot missing — skipping CountrySeeder.');

            return;
        }

        $records = json_decode((string) file_get_contents($path), true);

        if (! is_array($records)) {
            $this->command?->error('countries.json is not valid JSON — skipping CountrySeeder.');

            return;
        }

        $rows = [];
        $skipped = 0;
        $now = now();

        foreach ($records as $record) {
            $name = trim((string) ($record['name'] ?? ''));
            $iso2 = strtoupper(trim((string) ($record['iso2'] ?? '')));
            $iso3 = strtoupper(trim((string) ($record['iso3'] ?? '')));
            $nameAr = trim((string) ($record['name_ar'] ?? ''));

            // iso2/iso3 are the real identifiers — a record without them
            // cannot be matched reliably, so it is skipped, never guessed.
            if ($name === '' || strlen($iso2) !== 2 || strlen($iso3) !== 3) {
                $skipped++;

                continue;
            }

            $rows[] = [
                'name' => $name,
                'name_ar' => $nameAr !== '' ? $nameAr : null,
                'iso2' => $iso2,
                'iso3' => $iso3,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Figure out created vs. updated ahead of time (one cheap query)
        // instead of relying on per-row wasRecentlyCreated, which upsert()
        // does not report.
        $existingIso2 = array_fill_keys(
            Country::query()->pluck('iso2')->all(),
            true
        );

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            isset($existingIso2[$row['iso2']]) ? $updated++ : $created++;
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            Country::query()->upsert(
                $chunk,
                ['iso2'],
                ['name', 'name_ar', 'iso3', 'updated_at']
            );
        }

        $this->command?->info(
            "CountrySeeder: {$created} created, {$updated} updated, {$skipped} skipped."
        );
    }
}
