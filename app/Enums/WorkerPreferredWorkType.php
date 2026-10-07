<?php

declare(strict_types=1);

namespace App\Enums;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Services\UserCleaningOrderEstimationService;

enum WorkerPreferredWorkType: string
{
    case Cleaning = 'cleaning';
    case Events = 'events';
    case Hourly = 'hourly';

    /**
     * Legacy value kept only for reading older rows/clients.
     * It is intentionally not exposed as a selectable option anymore.
     */
    case Both = 'both';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function selectableValues(): array
    {
        return [
            self::Cleaning->value,
            self::Events->value,
            self::Hourly->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Cleaning->value => __('cleaning_admin.workers.preferred_work_type_options.cleaning'),
            self::Events->value => __('cleaning_admin.workers.preferred_work_type_options.events'),
            self::Hourly->value => __('cleaning_admin.workers.preferred_work_type_options.hourly'),
        ];
    }

    public function matchesPropertyType(?string $propertyType): bool
    {
        return match ($this) {
            self::Cleaning => $propertyType !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
            self::Events => $propertyType === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
            self::Hourly => false,
            self::Both => true,
        };
    }

    public function matchesBooking(CleaningBooking $booking): bool
    {
        if ((string) ($booking->booking_kind ?? 'standard') === 'open_time') {
            return $this === self::Hourly;
        }

        if ((string) $booking->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return $this === self::Events || $this === self::Both;
        }

        return $this === self::Cleaning || $this === self::Both;
    }
}
