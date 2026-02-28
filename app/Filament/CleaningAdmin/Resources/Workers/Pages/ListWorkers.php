<?php

namespace App\Filament\CleaningAdmin\Resources\Workers\Pages;

use App\Filament\CleaningAdmin\Resources\Workers\WorkerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
