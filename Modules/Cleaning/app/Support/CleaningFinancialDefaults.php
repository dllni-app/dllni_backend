<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

final class CleaningFinancialDefaults
{
    public const ROOM_TYPES = ['bedroom', 'bathroom', 'toilet', 'kitchen', 'living_room', 'balcony', 'corridor'];
    public const ROOM_SIZES = ['small', 'medium', 'large'];
    public const BASE_UNIT_PRICE = 30000.00;
    public const DEEP_CLEANING_MULTIPLIER = 4.00;
    public const AREA_MARGIN_MULTIPLIER = 1.18;
    public const SETUP_BUFFER_MINUTES = 22;

    public static function roomSizeRanges(): array
    {
        return [
            'bedroom' => ['small' => ['min' => 9.0, 'max' => 12.0, 'average' => 10.5], 'medium' => ['min' => 13.0, 'max' => 18.0, 'average' => 15.5], 'large' => ['min' => 19.0, 'max' => 25.0, 'average' => 22.0]],
            'bathroom' => ['small' => ['min' => 3.0, 'max' => 4.0, 'average' => 3.5], 'medium' => ['min' => 5.0, 'max' => 6.0, 'average' => 5.5], 'large' => ['min' => 7.0, 'max' => 9.0, 'average' => 8.0]],
            'toilet' => ['small' => ['min' => 1.5, 'max' => 2.0, 'average' => 1.75], 'medium' => ['min' => 2.5, 'max' => 3.0, 'average' => 2.75], 'large' => ['min' => 3.5, 'max' => 4.0, 'average' => 3.75]],
            'kitchen' => ['small' => ['min' => 6.0, 'max' => 9.0, 'average' => 7.5], 'medium' => ['min' => 10.0, 'max' => 14.0, 'average' => 12.0], 'large' => ['min' => 15.0, 'max' => 20.0, 'average' => 17.5]],
            'living_room' => ['small' => ['min' => 12.0, 'max' => 16.0, 'average' => 14.0], 'medium' => ['min' => 17.0, 'max' => 24.0, 'average' => 20.5], 'large' => ['min' => 25.0, 'max' => 35.0, 'average' => 30.0]],
            'balcony' => ['small' => ['min' => 3.0, 'max' => 5.0, 'average' => 4.0], 'medium' => ['min' => 6.0, 'max' => 9.0, 'average' => 7.5], 'large' => ['min' => 10.0, 'max' => 15.0, 'average' => 12.5]],
            'corridor' => ['small' => ['min' => 4.0, 'max' => 6.0, 'average' => 5.0], 'medium' => ['min' => 7.0, 'max' => 10.0, 'average' => 8.5], 'large' => ['min' => 11.0, 'max' => 15.0, 'average' => 13.0]],
        ];
    }

    public static function roomPricingUnits(): array
    {
        return [
            'bedroom' => ['small' => 1.0, 'medium' => 1.5, 'large' => 2.0],
            'bathroom' => ['small' => 0.5, 'medium' => 1.5, 'large' => 2.0],
            'toilet' => ['small' => 0.5, 'medium' => 1.5, 'large' => 2.0],
            'kitchen' => ['small' => 1.0, 'medium' => 1.5, 'large' => 2.0],
            'living_room' => ['small' => 1.0, 'medium' => 1.5, 'large' => 2.0],
            'balcony' => ['small' => 0.5, 'medium' => 1.5, 'large' => 2.0],
            'corridor' => ['small' => 0.25, 'medium' => 0.5, 'large' => 0.75],
        ];
    }

    public static function roomTimeMinutes(): array
    {
        return [
            'bedroom' => ['small' => ['regular' => 25, 'deep' => 50], 'medium' => ['regular' => 35, 'deep' => 70], 'large' => ['regular' => 45, 'deep' => 90]],
            'bathroom' => ['small' => ['regular' => 20, 'deep' => 45], 'medium' => ['regular' => 28, 'deep' => 55], 'large' => ['regular' => 35, 'deep' => 70]],
            'toilet' => ['small' => ['regular' => 10, 'deep' => 20], 'medium' => ['regular' => 14, 'deep' => 25], 'large' => ['regular' => 18, 'deep' => 30]],
            'kitchen' => ['small' => ['regular' => 30, 'deep' => 65], 'medium' => ['regular' => 40, 'deep' => 85], 'large' => ['regular' => 55, 'deep' => 110]],
            'living_room' => ['small' => ['regular' => 30, 'deep' => 60], 'medium' => ['regular' => 40, 'deep' => 80], 'large' => ['regular' => 55, 'deep' => 110]],
            'balcony' => ['small' => ['regular' => 12, 'deep' => 25], 'medium' => ['regular' => 18, 'deep' => 35], 'large' => ['regular' => 25, 'deep' => 45]],
            'corridor' => ['small' => ['regular' => 8, 'deep' => 15], 'medium' => ['regular' => 12, 'deep' => 22], 'large' => ['regular' => 16, 'deep' => 28]],
        ];
    }
}
