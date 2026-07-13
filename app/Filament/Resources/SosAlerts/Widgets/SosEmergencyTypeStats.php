<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Widgets;

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Cleaning\Models\CleaningBooking;

final class SosEmergencyTypeStats extends StatsOverviewWidget
{
    protected ?string $heading = 'ملخص بلاغات الطوارئ';

    protected function getStats(): array
    {
        $baseQuery = SosAlert::query()
            ->whereIn('booking_type', ['cleaning_booking', CleaningBooking::class]);

        $counts = (clone $baseQuery)
            ->selectRaw('emergency_type, COUNT(*) as total')
            ->groupBy('emergency_type')
            ->pluck('total', 'emergency_type');

        $openCount = (clone $baseQuery)
            ->whereIn('status', [
                SOSStatus::Pending->value,
                SOSStatus::Triggered->value,
                SOSStatus::Acknowledged->value,
            ])
            ->count();

        $stats = [];

        foreach (EmergencyType::cases() as $type) {
            $stats[] = Stat::make(
                SosAlertsTable::emergencyLabel($type),
                (string) ($counts[$type->value] ?? 0),
            )->color(SosAlertsTable::emergencyColor($type));
        }

        $stats[] = Stat::make('الإجمالي', (string) (int) $counts->sum())
            ->description("بلاغات مفتوحة: {$openCount}")
            ->color('gray');

        return $stats;
    }
}
