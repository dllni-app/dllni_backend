<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class AdminUiFormatter
{
    public static function formatNumber(
        float|int|string $value,
        int $decimals = 0,
    ): string {
        return number_format((float) $value, $decimals, '.', ',');
    }

    public static function formatCurrency(
        float|int|string $amount,
        int $decimals = 2,
        ?string $currency = null,
    ): string {
        $currencyCode = $currency ?? (string) config('app.currency', 'SYP');
        $suffix = self::currencySuffix($currencyCode);
        $formattedAmount = self::formatNumber($amount, $decimals);

        return mb_trim($formattedAmount.' '.$suffix);
    }

    private static function currencySuffix(string $currencyCode): string
    {
        return mb_strtoupper($currencyCode) === 'SYP'
            ? "\u{0644}.\u{0633}"
            : mb_strtoupper($currencyCode);
    }
}
