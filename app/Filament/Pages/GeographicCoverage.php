<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CleaningFinancialSetting;
use App\Models\WorkerZone;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class GeographicCoverage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedMap;

    protected string $view = 'filament.cleaning-admin.pages.geographic-coverage';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static ?int $navigationSort = 26;

    protected static bool $shouldRegisterNavigation = true;

    public string $search = '';

    public string $levelFilter = 'all';

    public string $dateRange = '7';

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.pages.geographic_coverage.title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.pages.geographic_coverage.description');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can('pricing.view') || $user->can('settings.view');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getViewData(): array
    {
        $thresholds = CleaningFinancialSetting::query()->first()?->coverage_thresholds ?? ['low' => 3, 'ok' => 7];
        $days = max(7, (int) $this->dateRange);

        $rows = WorkerZone::query()
            ->select('worker_zones.name')
            ->selectRaw('COUNT(DISTINCT worker_zones.worker_id) AS workers_count')
            ->selectRaw(
                "(
                    SELECT COUNT(*)
                    FROM cleaning_bookings
                    WHERE cleaning_bookings.scheduled_date >= CURDATE()
                      AND cleaning_bookings.scheduled_date < DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
                      AND cleaning_bookings.status IN ('pending','worker_assigned','in_progress')
                ) AS demand_count",
            )
            ->groupBy('worker_zones.name')
            ->orderBy('worker_zones.name')
            ->get()
            ->map(function (object $row) use ($thresholds): array {
                $workersCount = max((int) $row->workers_count, 1);
                $ratio = (int) ceil(((int) $row->demand_count) / $workersCount);
                $levelKey = 'ok';

                if ($ratio >= (int) ($thresholds['ok'] ?? 7)) {
                    $levelKey = 'high';
                } elseif ($ratio <= (int) ($thresholds['low'] ?? 3)) {
                    $levelKey = 'low';
                }

                return [
                    'zone' => (string) $row->name,
                    'workers_count' => (int) $row->workers_count,
                    'demand_count' => (int) $row->demand_count,
                    'coverage_ratio' => $ratio,
                    'level_key' => $levelKey,
                    'level_label' => __('cleaning_admin.pages.geographic_coverage.levels.' . $levelKey),
                    'level_tone' => $this->mapLevelTone($levelKey),
                ];
            })
            ->values();

        $search = trim($this->search);
        if ($search !== '') {
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['zone']), mb_strtolower($search)))->values();
        }

        if ($this->levelFilter !== 'all') {
            $rows = $rows->where('level_key', $this->levelFilter)->values();
        }

        return [
            'rows' => $rows,
            'summary' => [
                'regions_count' => $rows->count(),
                'high_pressure_count' => $rows->where('level_key', 'high')->count(),
                'workers_count' => $rows->sum('workers_count'),
            ],
            'filters' => [
                'dateRange' => [
                    '7' => __('cleaning_admin.filters.last_7_days'),
                    '14' => __('cleaning_admin.filters.last_14_days'),
                    '30' => __('cleaning_admin.filters.last_30_days'),
                ],
                'levels' => [
                    'all' => __('cleaning_admin.filters.all_levels'),
                    'high' => __('cleaning_admin.pages.geographic_coverage.levels.high'),
                    'ok' => __('cleaning_admin.pages.geographic_coverage.levels.ok'),
                    'low' => __('cleaning_admin.pages.geographic_coverage.levels.low'),
                ],
            ],
        ];
    }

    private function mapLevelTone(string $levelKey): string
    {
        return match ($levelKey) {
            'high' => 'danger',
            'low' => 'success',
            default => 'info',
        };
    }
}
