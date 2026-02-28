<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\CleaningFinancialSetting;
use App\Models\WorkerZone;
use Filament\Pages\Page;

final class GeographicCoverage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedMap;

    protected string $view = 'filament.cleaning-admin.pages.geographic-coverage';

    protected static ?string $navigationLabel = 'التغطية حسب المنطقة';

    protected static ?string $title = 'التغطية حسب المنطقة';

    protected static ?string $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 4;

    public function getViewData(): array
    {
        $thresholds = CleaningFinancialSetting::query()->first()?->coverage_thresholds ?? ['low' => 3, 'ok' => 7];
        $rows = WorkerZone::query()
            ->select('worker_zones.name')
            ->selectRaw('COUNT(DISTINCT worker_zones.worker_id) AS workers_count')
            ->selectRaw(
                "(
                    SELECT COUNT(*)
                    FROM cleaning_bookings
                    WHERE cleaning_bookings.scheduled_date >= CURDATE()
                      AND cleaning_bookings.scheduled_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                      AND cleaning_bookings.status IN ('pending','worker_assigned','in_progress')
                ) AS demand_count",
            )
            ->groupBy('worker_zones.name')
            ->orderBy('worker_zones.name')
            ->get()
            ->map(function (object $row) use ($thresholds): array {
                $workersCount = max((int) $row->workers_count, 1);
                $ratio = (int) ceil(((int) $row->demand_count) / $workersCount);
                $level = 'OK';

                if ($ratio >= (int) ($thresholds['ok'] ?? 7)) {
                    $level = 'High';
                } elseif ($ratio <= (int) ($thresholds['low'] ?? 3)) {
                    $level = 'Low';
                }

                return [
                    'zone' => $row->name,
                    'workers_count' => (int) $row->workers_count,
                    'demand_count' => (int) $row->demand_count,
                    'coverage_ratio' => $ratio,
                    'level' => $level,
                ];
            });

        return ['rows' => $rows];
    }
}
