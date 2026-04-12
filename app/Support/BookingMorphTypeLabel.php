<?php

declare(strict_types=1);

namespace App\Support;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Resturants\Models\Order;

final class BookingMorphTypeLabel
{
    public static function resolve(?string $bookingType): string
    {
        if ($bookingType === null || $bookingType === '') {
            return '-';
        }

        $key = match ($bookingType) {
            CleaningBooking::class => 'cleaning',
            EventBooking::class => 'event',
            Order::class => 'restaurant_order',
            'cleaning_booking', 'cleaning' => 'cleaning',
            'event' => 'event',
            default => null,
        };

        if ($key !== null) {
            return __('cleaning_admin.booking_morph_labels.'.$key);
        }

        return __('cleaning_admin.booking_morph_labels.unknown', [
            'type' => class_basename($bookingType),
        ]);
    }
}
