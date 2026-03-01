<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Pages;

use App\Filament\Resources\EventBookings\EventBookingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateEventBooking extends CreateRecord
{
    protected static string $resource = EventBookingResource::class;
}
