<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Pages;

use App\Filament\Resources\CleaningServices\CleaningServiceResource;
use App\Filament\Resources\CleaningServices\Widgets\CleaningServiceStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningServices extends ListRecords
{
    protected static string $resource = CleaningServiceResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.cleaning_services.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_services.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningServiceStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.cleaning_services.actions.create')),
        ];
    }
}
