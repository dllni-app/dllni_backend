<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases\Pages;

use App\Filament\Resources\WorkerComplianceCases\Widgets\WorkerComplianceOverviewStats;
use App\Filament\Resources\WorkerComplianceCases\WorkerComplianceCaseResource;
use Filament\Resources\Pages\ListRecords;

final class ListWorkerComplianceCases extends ListRecords
{
    protected static string $resource = WorkerComplianceCaseResource::class;

    protected function getHeaderWidgets(): array
    {
        return auth()->user()?->can('cleaning.worker_compliance.overview.view')
            ? [WorkerComplianceOverviewStats::class]
            : [];
    }
}
