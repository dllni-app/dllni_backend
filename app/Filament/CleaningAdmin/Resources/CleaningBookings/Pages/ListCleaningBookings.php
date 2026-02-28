<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleaningBookings extends ListRecords
{
    protected static string $resource = CleaningBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
