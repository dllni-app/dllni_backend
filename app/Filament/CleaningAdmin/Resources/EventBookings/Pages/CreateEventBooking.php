<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Pages;

use App\Filament\CleaningAdmin\Resources\EventBookings\EventBookingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventBooking extends CreateRecord
{
    protected static string $resource = EventBookingResource::class;
}
