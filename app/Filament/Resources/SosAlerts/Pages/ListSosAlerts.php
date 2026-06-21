<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Pages;

use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Filament\Resources\SosAlerts\Widgets\SosEmergencyTypeStats;
use Filament\Resources\Pages\ListRecords;

final class ListSosAlerts extends ListRecords
{
    protected static string $resource = SosAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SosEmergencyTypeStats::class,
        ];
    }
}
