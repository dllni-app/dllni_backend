<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCleaningBooking extends ViewRecord
{
    protected static string $resource = CleaningBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
