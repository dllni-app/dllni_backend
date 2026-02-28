<?php

namespace App\Filament\CleaningAdmin\Resources\Workers\Pages;

use App\Filament\CleaningAdmin\Resources\Workers\WorkerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWorker extends EditRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
