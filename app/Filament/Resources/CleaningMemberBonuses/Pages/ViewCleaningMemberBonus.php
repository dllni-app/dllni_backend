<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMemberBonuses\Pages;

use App\Filament\Resources\CleaningMemberBonuses\CleaningMemberBonusResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningMemberBonus extends ViewRecord
{
    protected static string $resource = CleaningMemberBonusResource::class;
}
