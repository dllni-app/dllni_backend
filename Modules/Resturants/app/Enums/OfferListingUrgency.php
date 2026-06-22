<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OfferListingUrgency: string
{
    case LimitedTime = 'limited_time';
    case EndingSoon = 'ending_soon';
    case TodaysOffer = 'todays_offer';

    public function label(): string
    {
        return match ($this) {
            self::LimitedTime => 'Limited time',
            self::EndingSoon => 'Ending soon',
            self::TodaysOffer => 'Today offer',
        };
    }
}
