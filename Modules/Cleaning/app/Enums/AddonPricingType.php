<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum AddonPricingType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
