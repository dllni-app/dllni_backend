<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningServices\CleaningServiceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningService extends CreateRecord
{
    protected static string $resource = CleaningServiceResource::class;
}
