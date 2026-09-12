<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterials\Pages;

use App\Filament\Resources\CleaningMaterials\CleaningMaterialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningMaterial extends EditRecord
{
    protected static string $resource = CleaningMaterialResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()->requiresConfirmation()]; }
}
