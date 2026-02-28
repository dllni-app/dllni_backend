<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCleaningBooking extends CreateRecord
{
    protected static string $resource = CleaningBookingResource::class;
}
