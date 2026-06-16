<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Filament\Resources\SystemAlerts\Widgets\SystemAlertStats;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListSystemAlerts extends ListRecords
{
    protected static string $resource = SystemAlertResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.system_alerts.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.system_alerts.list');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SystemAlertStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
