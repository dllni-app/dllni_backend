<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class AdminUiFormatter
{
    /**
     * @var array<string, string>
     */
    private const ARABIC_DIGITS_MAP = [
        '0' => "\u{0660}",
        '1' => "\u{0661}",
        '2' => "\u{0662}",
        '3' => "\u{0663}",
        '4' => "\u{0664}",
        '5' => "\u{0665}",
        '6' => "\u{0666}",
        '7' => "\u{0667}",
        '8' => "\u{0668}",
        '9' => "\u{0669}",
        ',' => "\u{066C}",
        '.' => "\u{066B}",
    ];

    public static function formatNumber(
        float|int|string $value,
        int $decimals = 0,
        bool $arabicNumerals = true,
    ): string {
        $formatted = number_format((float) $value, $decimals, '.', ',');

        if (! $arabicNumerals) {
            return $formatted;
        }

        return strtr($formatted, self::ARABIC_DIGITS_MAP);
    }

    public static function formatCurrency(
        float|int|string $amount,
        int $decimals = 2,
        ?string $currency = null,
        bool $arabicNumerals = true,
    ): string {
        $currencyCode = $currency ?? (string) config('app.currency', 'SYP');
        $suffix = self::currencySuffix($currencyCode);
        $formattedAmount = self::formatNumber($amount, $decimals, $arabicNumerals);

        return trim($formattedAmount . ' ' . $suffix);
    }

    private static function currencySuffix(string $currencyCode): string
    {
        return strtoupper($currencyCode) === 'SYP'
            ? "\u{0644}.\u{0633}"
            : strtoupper($currencyCode);
    }
}
