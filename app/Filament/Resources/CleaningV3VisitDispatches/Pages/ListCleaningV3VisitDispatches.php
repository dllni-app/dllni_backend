<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3VisitDispatches\Pages;

use App\Filament\Resources\CleaningV3VisitDispatches\CleaningV3VisitDispatchResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningV3VisitDispatches extends ListRecords
{
    protected static string $resource = CleaningV3VisitDispatchResource::class;
}
