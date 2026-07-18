<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Pages;

use App\Filament\Resources\CleaningHomeTypes\CleaningHomeTypeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningHomeType extends CreateRecord
{
    protected static string $resource = CleaningHomeTypeResource::class;
}
