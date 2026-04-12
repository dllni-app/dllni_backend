<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningBookings extends ListRecords
{
    protected static string $resource = CleaningBookingResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_bookings.list');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
