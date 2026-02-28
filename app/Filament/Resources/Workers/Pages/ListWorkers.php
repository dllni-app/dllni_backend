<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.workers.tooltip');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('cleaning_admin.workers.add')),
        ];
    }
}
