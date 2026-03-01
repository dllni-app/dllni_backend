<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Pages;

use App\Filament\Resources\EventBookings\EventBookingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEventBooking extends ViewRecord
{
    protected static string $resource = EventBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
