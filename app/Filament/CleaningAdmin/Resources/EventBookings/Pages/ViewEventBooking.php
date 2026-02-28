<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Pages;

use App\Filament\CleaningAdmin\Resources\EventBookings\EventBookingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEventBooking extends ViewRecord
{
    protected static string $resource = EventBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
