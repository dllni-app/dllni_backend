<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Pages;

use App\Filament\Resources\EventBookings\EventBookingResource;
use Filament\Resources\Pages\ListRecords;

final class ListEventBookings extends ListRecords
{
    protected static string $resource = EventBookingResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.event_bookings.list');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
