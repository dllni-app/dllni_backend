<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum AddonPricingType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'ثابت',
            self::Percentage => 'نسبة مئوية',
        };
    }
}
