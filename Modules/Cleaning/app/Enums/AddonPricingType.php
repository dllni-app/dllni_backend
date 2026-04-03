<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum AddonPricingType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return __('cleaning_admin.enums.addon_pricing_type.'.$this->value);
    }
}
