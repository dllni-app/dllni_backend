<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningBooking extends EditRecord
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string
    {
        return 'تعديل حجز تنظيف';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('عرض'),
        ];
    }
}
