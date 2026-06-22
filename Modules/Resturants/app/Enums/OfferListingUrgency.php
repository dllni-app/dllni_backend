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
            self::LimitedTime => "\u{0644}\u{0641}\u{062A}\u{0631}\u{0629} \u{0645}\u{062D}\u{062F}\u{0648}\u{062F}\u{0629}",
            self::EndingSoon => "\u{064A}\u{0646}\u{062A}\u{0647}\u{064A} \u{0642}\u{0631}\u{064A}\u{0628}\u{0627}\u{064B}",
            self::TodaysOffer => "\u{0639}\u{0631}\u{0636} \u{0627}\u{0644}\u{064A}\u{0648}\u{0645}",
        };
    }
}
