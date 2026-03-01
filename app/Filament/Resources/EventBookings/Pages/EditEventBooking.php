<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Pages;

use App\Filament\Resources\EventBookings\EventBookingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditEventBooking extends EditRecord
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
