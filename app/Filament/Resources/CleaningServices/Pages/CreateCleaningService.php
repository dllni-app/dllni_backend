<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Pages;

use App\Filament\Resources\CleaningServices\CleaningServiceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningService extends CreateRecord
{
    protected static string $resource = CleaningServiceResource::class;
}
