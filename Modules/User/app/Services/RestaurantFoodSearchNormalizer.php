<?php

declare(strict_types=1);

namespace Modules\User\Services;

use InvalidArgumentException;

/**
 * Conservative Arabic search-key normalization. Never changes stored
 * restaurant/product names; only compares normalized query projections.
 */
final class RestaurantFoodSearchNormalizer
{
    /** @return array<string, string> */
    private static function replacements(): array
    {
        return [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        ];
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = strtr($value, self::replacements());
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }

    public static function likePattern(string $value, bool $prefix = false): string
    {
        $escaped = addcslashes(self::normalize($value), '%_\\');

        return ($prefix ? '' : '%').$escaped.'%';
    }

    /** SQL fragment is built ONLY for hard-coded, allowlisted columns. */
    public static function sql(string $column): string
    {
        if (! in_array($column, [
            'products.name',
            'restaurants.name',
            'products.description',
        ], true)) {
            throw new InvalidArgumentException('Unknown food-search SQL column.');
        }

        $expression = "LOWER(COALESCE({$column}, ''))";
        foreach (self::replacements() as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }
        foreach (['ـ', 'َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ّ', 'ٰ'] as $mark) {
            $expression = "REPLACE({$expression}, '{$mark}', '')";
        }

        return $expression;
    }
}
