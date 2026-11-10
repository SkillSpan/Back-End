<?php

namespace App\Support;

final class ReferenceCache
{
    public const TTL_SECONDS = 12 * 60 * 60;

    public const UNIVERSITIES = 'reference:universities';

    public const SPECIALIZATIONS = 'reference:specializations';

    public const COUNTRIES = 'reference:countries';

    public const SKILLS_TAXONOMY = 'reference:skills-taxonomy';

    public static function countryUniversities(int $countryId): string
    {
        return 'reference:countries:'.$countryId.':universities';
    }

    public static function flush(): void
    {
        foreach ([self::UNIVERSITIES, self::SPECIALIZATIONS, self::COUNTRIES, self::SKILLS_TAXONOMY] as $key) {
            cache()->forget($key);
        }
    }
}
