<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCleaningBooking extends EditRecord
{
    protected static string $resource = CleaningBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
