<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Pages;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Services\CleaningWorkerNotificationService;

final class CreateCleaningNeighborhood extends CreateRecord
{
    protected static string $resource = CleaningNeighborhoodResource::class;

    protected function afterCreate(): void
    {
        if (! $this->record instanceof CleaningNeighborhood) {
            return;
        }

        app(CleaningWorkerNotificationService::class)->notifyNewNeighborhood($this->record);
    }
}
