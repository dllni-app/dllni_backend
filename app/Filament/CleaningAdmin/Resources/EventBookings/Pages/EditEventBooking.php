<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Pages;

use App\Filament\CleaningAdmin\Resources\EventBookings\EventBookingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEventBooking extends EditRecord
{
    protected static string $resource = EventBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
