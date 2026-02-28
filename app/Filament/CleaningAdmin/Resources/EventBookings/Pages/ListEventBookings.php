<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Pages;

use App\Filament\CleaningAdmin\Resources\EventBookings\EventBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEventBookings extends ListRecords
{
    protected static string $resource = EventBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
