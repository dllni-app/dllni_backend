<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Pages;

use App\Filament\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningTimeWarning extends CreateRecord
{
    protected static string $resource = CleaningTimeWarningResource::class;
}
