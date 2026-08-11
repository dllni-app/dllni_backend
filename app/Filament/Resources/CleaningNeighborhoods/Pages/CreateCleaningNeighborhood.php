<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Pages;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningNeighborhood extends CreateRecord
{
    protected static string $resource = CleaningNeighborhoodResource::class;
}
