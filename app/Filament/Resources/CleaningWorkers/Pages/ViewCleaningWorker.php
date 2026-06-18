<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Pages;

use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningWorker extends ViewRecord
{
    protected static string $resource = CleaningWorkerResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->worker) {
            $this->redirect(WorkerResource::getUrl('view', ['record' => $this->record->worker]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
