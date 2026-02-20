<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingService
{
    public function store(CleaningBookingData $data): CleaningBooking
    {
        return DB::transaction(static function () use ($data) {
            $booking = CleaningBooking::create($data->onlyModelAttributes());

            return $booking;
        });
    }

    public function update(CleaningBookingData $data, CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($data, $booking) {
            tap($booking)->update($data->onlyModelAttributes());

            return $booking;
        });
    }
}
