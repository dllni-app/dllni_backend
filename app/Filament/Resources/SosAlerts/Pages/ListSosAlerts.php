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
        return 'النزاعات والشكاوى';
    }

    public function getSubheading(): ?string
    {
        return 'متابعة البلاغات الواردة من تطبيق المستخدم وتطبيق عامل التنظيف ضمن واجهة واحدة.';
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
