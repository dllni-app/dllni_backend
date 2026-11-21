<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions\Pages;

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningV3CatalogVersions extends ListRecords
{
    protected static string $resource = CleaningV3CatalogVersionResource::class;
}
