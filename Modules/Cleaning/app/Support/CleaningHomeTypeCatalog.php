<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningHomeType;
use Throwable;

final class CleaningHomeTypeCatalog
{
    private const LEGACY_PROPERTY_TYPES = [
        'apartment',
        'villa',
        'house',
        'office',
        'studio',
    ];

    private const LEGACY_OCCASION_TYPES = [
        'family_dinner',
        'birthday',
        'large_gathering',
        'funeral',
        'other',
    ];

    public static function propertyTypeCodes(): array
    {
        return self::uniqueCodes([
            ...self::regularPropertyTypeCodes(),
            'event_assistance',
        ]);
    }

    public static function regularPropertyTypeCodes(): array
    {
        return self::uniqueCodes([
            ...self::LEGACY_PROPERTY_TYPES,
            ...self::databaseCodes(CleaningHomeType::SECTION_PROPERTY),
        ]);
    }

    public static function occasionTypeCodes(): array
    {
        return self::uniqueCodes([
            ...self::LEGACY_OCCASION_TYPES,
            ...self::databaseCodes(CleaningHomeType::SECTION_OCCASION),
        ]);
    }

    private static function databaseCodes(string $section): array
    {
        try {
            if (! Schema::hasTable('cleaning_home_types')) {
                return [];
            }

            return CleaningHomeType::query()
                ->withTrashed()
                ->forSection($section)
                ->pluck('code')
                ->filter(static fn (mixed $code): bool => is_string($code) && trim($code) !== '')
                ->map(static fn (string $code): string => mb_strtolower(trim($code)))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function uniqueCodes(array $codes): array
    {
        return array_values(array_unique(array_map(
            static fn (string $code): string => mb_strtolower(trim($code)),
            $codes,
        )));
    }
}
