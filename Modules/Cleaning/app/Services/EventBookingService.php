<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Data\EventBookingData;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingService
{
    public function store(EventBookingData $data): EventBooking
    {
        return DB::transaction(static function () use ($data) {
            $booking = EventBooking::create($data->onlyModelAttributes());

            return $booking;
        });
    }

    public function update(EventBookingData $data, EventBooking $booking): EventBooking
    {
        return DB::transaction(static function () use ($data, $booking) {
            tap($booking)->update($data->onlyModelAttributes());

            return $booking;
        });
    }
}
