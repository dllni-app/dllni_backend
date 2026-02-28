<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningServices\CleaningServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCleaningService extends EditRecord
{
    protected static string $resource = CleaningServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
