<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningBooking extends ViewRecord
{
    protected static string $resource = CleaningBookingResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('view_dispute')
                ->label('عرض النزاع')
                ->url(fn () => $this->record->disputes()->first()
                    ? DisputeResource::getUrl('view', ['record' => $this->record->disputes()->first()])
                    : '#')
                ->visible(fn () => $this->record->disputes()->exists()),
            EditAction::make(),
        ];

        return $actions;
    }
}
