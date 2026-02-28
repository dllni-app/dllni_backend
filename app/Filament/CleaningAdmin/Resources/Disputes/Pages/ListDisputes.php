<?php

namespace App\Filament\CleaningAdmin\Resources\Disputes\Pages;

use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
