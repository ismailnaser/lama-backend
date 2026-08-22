<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PatientSectionCache
{
    public static function countKey(string $section): string
    {
        return 'patients:count:'.$section;
    }

    public static function count(string $section, callable $resolver): int
    {
        return (int) Cache::remember(self::countKey($section), 20, $resolver);
    }

    public static function forget(string $section): void
    {
        Cache::forget(self::countKey($section));
    }
}
