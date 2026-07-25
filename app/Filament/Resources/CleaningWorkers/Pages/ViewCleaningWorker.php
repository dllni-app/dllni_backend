<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\CleaningWorkers\Support\AdjustWorkerTrustScoreAction;
use App\Filament\Resources\CleaningWorkers\Widgets\CleaningWorkerPenaltyStats;
use App\Filament\Resources\Workers\Support\WorkerSuspensionActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningWorker extends ViewRecord
{
    protected static string $resource = CleaningWorkerResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningWorkerPenaltyStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            AdjustWorkerTrustScoreAction::make(),
            ...WorkerSuspensionActions::make(),
            EditAction::make(),
        ];
    }
}
