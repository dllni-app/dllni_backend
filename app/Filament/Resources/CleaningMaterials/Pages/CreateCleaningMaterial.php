<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterials\Pages;

use App\Filament\Resources\CleaningMaterials\CleaningMaterialResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningMaterial extends CreateRecord
{
    protected static string $resource = CleaningMaterialResource::class;
}
