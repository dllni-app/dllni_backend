<?php

declare(strict_types=1);

namespace Modules\User\Services;

final class SmartSearchUnitNormalizer
{
    /** @var array<string, string> */
    private const array ALIASES = [
        'kg' => 'kg',
        'kilo' => 'kg',
        'kilogram' => 'kg',
        'كيلو' => 'kg',
        'كيلوغرام' => 'kg',
        'كيلو غرام' => 'kg',
        'كغ' => 'kg',
        'g' => 'g',
        'gram' => 'g',
        'grams' => 'g',
        'غرام' => 'g',
        'غ' => 'g',
        'l' => 'l',
        'liter' => 'l',
        'litre' => 'l',
        'ليتر' => 'l',
        'لتر' => 'l',
        'ml' => 'ml',
        'milliliter' => 'ml',
        'ميلي' => 'ml',
        'مل' => 'ml',
        'piece' => 'piece',
        'حبة' => 'piece',
        'حبه' => 'piece',
        'قطعة' => 'piece',
        'pack' => 'pack',
        'عبوة' => 'pack',
        'باكيت' => 'pack',
    ];

    public function normalize(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $key = $this->normalizeText($unit);

        return self::ALIASES[$key] ?? ($key !== '' ? $key : null);
    }

    public function convert(float $value, string $from, string $to): ?float
    {
        $from = $this->normalize($from) ?? $from;
        $to = $this->normalize($to) ?? $to;

        if ($from === $to) {
            return $value;
        }

        return match ([$from, $to]) {
            ['kg', 'g'] => $value * 1000,
            ['g', 'kg'] => $value / 1000,
            ['l', 'ml'] => $value * 1000,
            ['ml', 'l'] => $value / 1000,
            default => null,
        };
    }

    public function packagesRequired(
        ?float $requestedQuantity,
        ?string $requestedUnit,
        ?float $packageQuantity,
        ?string $packageUnit,
    ): ?array {
        if ($requestedQuantity === null || $requestedQuantity <= 0 || $packageQuantity === null || $packageQuantity <= 0) {
            return null;
        }

        $requestedUnit = $this->normalize($requestedUnit);
        $packageUnit = $this->normalize($packageUnit);

        if ($requestedUnit === null || $packageUnit === null) {
            return null;
        }

        $converted = $this->convert($requestedQuantity, $requestedUnit, $packageUnit);
        if ($converted === null) {
            return null;
        }

        $packages = (int) ceil($converted / $packageQuantity);
        $fulfilled = $packages * $packageQuantity;

        return [
            'packages' => $packages,
            'fulfilledQuantity' => $fulfilled,
            'fulfilledUnit' => $packageUnit,
            'overage' => max(0.0, $fulfilled - $converted),
            'exact' => abs($fulfilled - $converted) < 0.0001,
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }
}
