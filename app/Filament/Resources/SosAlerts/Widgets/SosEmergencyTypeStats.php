<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Widgets;

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\UserModuleType;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBooking;

final class SosEmergencyTypeStats extends StatsOverviewWidget
{
    protected ?string $heading = 'ملخص البلاغات والشكاوى الواردة';

    protected function getStats(): array
    {
        $baseQuery = SosAlert::query()
            ->where('booking_type', CleaningBooking::class);

        $counts = (clone $baseQuery)
            ->selectRaw('emergency_type, COUNT(*) as total')
            ->groupBy('emergency_type')
            ->pluck('total', 'emergency_type');

        $openCount = (clone $baseQuery)
            ->whereIn('status', [SOSStatus::Pending->value, SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
            ->count();

        $workerAppCount = (clone $baseQuery)
            ->where(function (Builder $query): void {
                $query->where('source', 'cleaning_owner_app')
                    ->orWhere(function (Builder $query): void {
                        $query->where('source', 'booking')
                            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('module_type', UserModuleType::CleaningWorker->value));
                    });
            })
            ->count();

        $userAppCount = (clone $baseQuery)
            ->where(function (Builder $query): void {
                $query->where('source', 'dllni_user_app')
                    ->orWhere(function (Builder $query): void {
                        $query->where('source', 'booking')
                            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery
                                ->whereNull('module_type')
                                ->orWhere('module_type', '!=', UserModuleType::CleaningWorker->value));
                    });
            })
            ->count();

        $stats = [];

        foreach (EmergencyType::cases() as $type) {
            $stats[] = Stat::make(
                SosAlertsTable::emergencyLabel($type),
                (string) ($counts[$type->value] ?? 0),
            )->color(SosAlertsTable::emergencyColor($type));
        }

        $stats[] = Stat::make('الإجمالي', (string) (int) $counts->sum())
            ->description("مفتوحة: {$openCount} | تطبيق المستخدم: {$userAppCount} | تطبيق العامل: {$workerAppCount}")
            ->color('gray');

        return $stats;
    }
}
