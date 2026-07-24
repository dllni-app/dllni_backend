<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Pages;

use App\Filament\Resources\CleaningFinancialPenalties\CleaningFinancialPenaltyResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningFinancialPenalty extends ViewRecord
{
    protected static string $resource = CleaningFinancialPenaltyResource::class;

    public function getTitle(): string
    {
        return 'عرض الغرامة المالية';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
