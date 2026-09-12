<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingSessionCoverageStatus: string
{
    case Searching = 'searching';
    case PartiallyCovered = 'partially_covered';
    case FullyCovered = 'fully_covered';

    public function label(): string
    {
        return match ($this) {
            self::Searching => 'جاري البحث عن عمال',
            self::PartiallyCovered => 'مغطاة جزئياً',
            self::FullyCovered => 'مغطاة بالكامل',
        };
    }
}
