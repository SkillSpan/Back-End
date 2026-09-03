<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Country reference data. Reads the locally stored, pre-filtered snapshot
 * of github.com/mledoze/countries (database/data/countries.json — name,
 * name_ar, iso2, iso3 only). iso2 is the external identifier, so re-runs
 * update in place instead of duplicating rows.
 */
class CountrySeeder extends Seeder
{
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

        $created = 0;
        $updated = 0;
        $skipped = 0;

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

            $country = Country::updateOrCreate(
                ['iso2' => $iso2],
                [
                    'name' => $name,
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'iso3' => $iso3,
                ]
            );

            $country->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info(
            "CountrySeeder: {$created} created, {$updated} updated, {$skipped} skipped."
        );
    }
}
