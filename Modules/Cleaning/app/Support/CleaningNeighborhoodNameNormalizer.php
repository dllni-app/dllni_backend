<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

final class CleaningNeighborhoodNameNormalizer
{
    public const ALEPPO_CITY = "\u{062d}\u{0644}\u{0628}";

    /**
     * @param  array<int, string>|null  $aliases
     * @return array<int, string>
     */
    public static function normalizeAliases(?array $aliases): array
    {
        if (! is_array($aliases)) {
            return [];
        }

        $normalized = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias)) {
                continue;
            }

            $alias = self::repairText($alias);

            if ($alias === '' || in_array($alias, $normalized, true)) {
                continue;
            }

            $normalized[] = $alias;
        }

        return $normalized;
    }

    public static function normalize(?string $value): string
    {
        $value = self::repairText($value);

        if ($value === '') {
            return '';
        }

        // Keep this logic aligned with Flutter's work-area normalization:
        // text
        //   .replaceAll(RegExp(r'^(حي\s+)'), '')
        //   .replaceAll(RegExp(r'[ىي]'), 'ي')
        //   .replaceAll(RegExp(r'[أإآا]'), 'ا')
        //   .trim();
        $value = preg_replace('/^حي\s+/u', '', $value) ?? $value;
        $value = strtr($value, [
            'ى' => 'ي',
            'ي' => 'ي',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ا' => 'ا',
        ]);

        return mb_trim($value);
    }

    public static function canonicalCityName(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return null;
        }

        $normalizedLower = mb_strtolower($normalized);

        if (
            str_contains($normalized, 'حلب')
            || str_contains($normalizedLower, 'aleppo')
            || str_contains($normalizedLower, 'halab')
        ) {
            return self::ALEPPO_CITY;
        }

        return self::repairText($value);
    }

    public static function repairText(?string $value): string
    {
        $value = mb_trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (! self::looksLikeMojibake($value)) {
            return $value;
        }

        $decoded = iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
        if (! is_string($decoded) || $decoded === '' || preg_match('//u', $decoded) !== 1) {
            return $value;
        }

        return mb_trim($decoded);
    }

    private static function looksLikeMojibake(string $value): bool
    {
        return str_contains($value, 'Ø')
            || str_contains($value, 'Ù')
            || str_contains($value, 'Û')
            || str_contains($value, 'Ã');
    }
}
