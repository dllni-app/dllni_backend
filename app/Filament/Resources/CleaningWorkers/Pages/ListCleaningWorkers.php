<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\CleaningWorkers\Widgets\CleaningWorkerSummaryStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningWorkers extends ListRecords
{
    protected static string $resource = CleaningWorkerResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningWorkerSummaryStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إضافة عامل'),
        ];
    }
}
