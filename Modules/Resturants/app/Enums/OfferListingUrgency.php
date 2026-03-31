<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OfferListingUrgency: string
{
    case LimitedTime = 'limited_time';
    case EndingSoon = 'ending_soon';
    case TodaysOffer = 'todays_offer';
}
