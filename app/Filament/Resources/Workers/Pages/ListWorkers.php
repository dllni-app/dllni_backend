<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use App\Filament\Resources\Workers\Widgets\WorkerStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.workers.plural');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.workers.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            WorkerStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.workers.add'))
                ->modal(),
        ];
    }
}
