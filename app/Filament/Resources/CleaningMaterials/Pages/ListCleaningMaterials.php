<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterials\Pages;

use App\Filament\Resources\CleaningMaterials\CleaningMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningMaterials extends ListRecords
{
    protected static string $resource = CleaningMaterialResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
