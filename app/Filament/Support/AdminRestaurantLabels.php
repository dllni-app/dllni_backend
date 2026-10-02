<?php

declare(strict_types=1);

namespace App\Filament\Support;

use BackedEnum;
use Illuminate\Support\Facades\Lang;

final class AdminRestaurantLabels
{
    public static function availabilityMode(?string $value): string
    {
        return self::translated('restaurant_admin.enums.availability_mode', $value);
    }

    public static function discountType(mixed $value): string
    {
        return self::translated('restaurant_admin.enums.discount_type', self::enumValue($value));
    }

    public static function offerUrgency(?string $value): string
    {
        return self::translated('restaurant_admin.enums.offer_urgency', $value);
    }

    public static function inventoryUnit(mixed $value): string
    {
        $raw = self::enumValue($value);

        if ($raw === null || $raw === '') {
            return '—';
        }

        $normalized = match (mb_strtolower(mb_trim($raw))) {
            'kg', 'kilogram', 'kilograms', 'كيلو', 'كيلوغرام' => 'kg',
            'g', 'gram', 'grams', 'غرام' => 'g',
            'l', 'liter', 'litre', 'liters', 'litres', 'لتر' => 'l',
            'ml', 'milliliter', 'millilitre', 'milliliters', 'millilitres', 'مل' => 'ml',
            'piece', 'pieces', 'pcs', 'حبة', 'قطعة' => 'piece',
            default => null,
        };

        return $normalized !== null
            ? self::translated('restaurant_admin.enums.inventory_unit', $normalized)
            : $raw;
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private static function translated(string $prefix, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $key = $prefix.'.'.$value;

        return Lang::has($key) ? __($key) : $value;
    }
}
