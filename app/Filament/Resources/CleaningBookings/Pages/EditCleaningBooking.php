<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningBooking extends EditRecord
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
