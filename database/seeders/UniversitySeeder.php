<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\University;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    private function backfillExistingRows(array $countriesByIso2): int
    {
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
