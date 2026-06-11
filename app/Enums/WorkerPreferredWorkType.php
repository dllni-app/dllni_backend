<?php

declare(strict_types=1);

namespace App\Enums;

use Modules\User\Services\UserCleaningOrderEstimationService;

enum WorkerPreferredWorkType: string
{
    case Cleaning = 'cleaning';
    case Events = 'events';
    case Both = 'both';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Cleaning->value => __('cleaning_admin.workers.preferred_work_type_options.cleaning'),
            self::Events->value => __('cleaning_admin.workers.preferred_work_type_options.events'),
            self::Both->value => __('cleaning_admin.workers.preferred_work_type_options.both'),
        ];
    }

    public function matchesPropertyType(?string $propertyType): bool
    {
        return match ($this) {
            self::Cleaning => $propertyType !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
            self::Events => $propertyType === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
            self::Both => true,
        };
    }
}
