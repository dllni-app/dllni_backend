<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OfferRewardType: string
{
    case SameProduct = 'same_product';
    case CheapestEligible = 'cheapest_eligible';
    case SpecificProducts = 'specific_products';
}
