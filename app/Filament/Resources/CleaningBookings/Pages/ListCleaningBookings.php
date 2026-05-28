<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningBookings\Widgets\CleaningBookingStats;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningBookings extends ListRecords
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.cleaning_bookings.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_bookings.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningBookingStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
