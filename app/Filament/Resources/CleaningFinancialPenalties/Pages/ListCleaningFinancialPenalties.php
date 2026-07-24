<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Pages;

use App\Filament\Resources\CleaningFinancialPenalties\CleaningFinancialPenaltyResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningFinancialPenalties extends ListRecords
{
    protected static string $resource = CleaningFinancialPenaltyResource::class;
}
