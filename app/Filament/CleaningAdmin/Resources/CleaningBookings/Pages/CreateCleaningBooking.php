<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningBooking extends CreateRecord
{
    protected static string $resource = CleaningBookingResource::class;
}
