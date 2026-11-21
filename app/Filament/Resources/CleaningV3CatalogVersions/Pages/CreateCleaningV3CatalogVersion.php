<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions\Pages;

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningV3CatalogVersion extends CreateRecord
{
    protected static string $resource = CleaningV3CatalogVersionResource::class;
}
