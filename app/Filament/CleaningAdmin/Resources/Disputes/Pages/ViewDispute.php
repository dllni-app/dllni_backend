<?php

namespace App\Filament\CleaningAdmin\Resources\Disputes\Pages;

use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
