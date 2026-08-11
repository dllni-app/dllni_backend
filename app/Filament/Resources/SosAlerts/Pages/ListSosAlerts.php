<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Pages;

use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Filament\Resources\SosAlerts\Widgets\SosEmergencyTypeStats;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListSosAlerts extends ListRecords
{
    protected static string $resource = SosAlertResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'بلاغات الطوارئ (SOS)';
    }

    public function getSubheading(): ?string
    {
        return 'متابعة بلاغات الطوارئ الواردة من تطبيق المستخدم وتطبيق عامل التنظيف.';
    }

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
