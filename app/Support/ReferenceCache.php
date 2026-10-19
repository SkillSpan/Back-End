<?php

namespace App\Support;

/**
 * Cache keys and TTL for the read-only reference data the frontend loads on
 * almost every page: the university / specialization / country dropdowns and
 * the skills taxonomy.
 *
 * WHY THIS EXISTS
 * ---------------
 * The database is hosted on a different provider from the application, so a
 * single round trip costs roughly a second. `/reference/universities` measured
 * 3.0-4.5 s and 541 KB of JSON before this cache existed, and
 * `/reference/specializations` took 1.29 s to return 1.5 KB — that is network
 * latency, not a slow query.
 *
 * The data behind these keys is seeded reference data that the application
 * never writes (the admin panel only *reads* specializations), so a long TTL is
 * safe. A redeploy also starts a fresh container with an empty file cache, so
 * changes made by a seeder appear on the next deploy regardless of TTL.
 *
 * If reference data ever becomes editable at runtime, call {@see flush()} from
 * the writing endpoint — do not shorten the TTL instead.
 */
final class ReferenceCache
{
    /**
     * Twelve hours. Long on purpose: the payload changes only when a seeder
     * runs, which happens at container start.
     */
    public const TTL_SECONDS = 12 * 60 * 60;

    public const UNIVERSITIES = 'reference:universities';

    public const SPECIALIZATIONS = 'reference:specializations';

    public const COUNTRIES = 'reference:countries';

    public const SKILLS_TAXONOMY = 'reference:skills-taxonomy';

    /**
     * The dependent university select is per country, so it needs its own key
     * per country id rather than one shared list.
     */
    public static function countryUniversities(int $countryId): string
    {
        return 'reference:countries:'.$countryId.':universities';
    }

    /**
     * Drop every reference list. Only needed if one of them becomes writable.
     */
    public static function flush(): void
    {
        foreach ([self::UNIVERSITIES, self::SPECIALIZATIONS, self::COUNTRIES, self::SKILLS_TAXONOMY] as $key) {
            cache()->forget($key);
        }
    }
}
