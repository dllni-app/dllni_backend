<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Pages;

use App\Filament\Resources\ServiceAddons\ServiceAddonResource;
use App\Filament\Resources\ServiceAddons\Widgets\ServiceAddonStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListServiceAddons extends ListRecords
{
    protected static string $resource = ServiceAddonResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.service_addons.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.service_addons.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ServiceAddonStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.service_addons.actions.create')),
        ];
    }
}
