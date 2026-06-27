<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMemberBonuses\Pages;

use App\Filament\Resources\CleaningMemberBonuses\CleaningMemberBonusResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningMemberBonuses extends ListRecords
{
    protected static string $resource = CleaningMemberBonusResource::class;

    public function getSubheading(): ?string
    {
        return 'Bonuses are created automatically when a member reaches a loyalty rule. Admin activation is required before the member can use the bonus.';
    }
}
