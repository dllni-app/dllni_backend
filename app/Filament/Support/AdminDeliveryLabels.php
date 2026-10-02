<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Facades\Lang;

final class AdminDeliveryLabels
{
    public static function orderStatus(?string $status): string
    {
        return self::translated('delivery_company.orders.enums.status', $status);
    }

    public static function attemptStatus(?string $status): string
    {
        return self::translated('delivery_company.orders.enums.attempt_status', $status);
    }

    public static function driverAvailability(?string $status): string
    {
        return self::translated('delivery_company.drivers.enums.availability', $status);
    }

    public static function vehicleType(?string $type): string
    {
        return self::translated('delivery_company.drivers.enums.vehicle_type', $type);
    }

    public static function dispatchPhase(?string $phase): string
    {
        return self::translated('delivery_company.orders.enums.dispatch_phase', $phase);
    }

    public static function failureCode(?string $code): string
    {
        return self::translated('delivery_company.orders.enums.failure_code', $code);
    }

    public static function merchantStatus(?string $status, ?string $sourceType): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        $prefix = match ($sourceType) {
            'restaurant_order' => 'restaurant_admin.enums.order_status',
            'supermarket_order' => 'supermarket_admin.enums.order_status',
            default => null,
        };

        if ($prefix !== null) {
            $key = $prefix.'.'.$status;

            if (Lang::has($key)) {
                return __($key);
            }
        }

        foreach (['restaurant_admin.enums.order_status', 'supermarket_admin.enums.order_status'] as $candidatePrefix) {
            $key = $candidatePrefix.'.'.$status;

            if (Lang::has($key)) {
                return __($key);
            }
        }

        return self::orderStatus($status);
    }

    public static function note(?string $note): string
    {
        if ($note === null || mb_trim($note) === '') {
            return '—';
        }

        $key = match (mb_trim($note)) {
            'No eligible drivers are currently available.' => 'delivery_company.orders.system_notes.no_eligible_drivers',
            'All eligible drivers rejected or timed out.' => 'delivery_company.orders.system_notes.driver_pool_exhausted',
            'No drivers in current radius; expanding search.' => 'delivery_company.orders.system_notes.no_drivers_in_radius',
            'Company is suspended.' => 'delivery_company.orders.system_notes.company_suspended',
            default => null,
        };

        return $key !== null ? __($key) : $note;
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
