<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CleaningFinancialSetting;
use App\Models\WorkerZone;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;

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

    public function getTitle(): string|Htmlable
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
        $today = now()->toDateString();
        $endDate = now()->addDays($days)->toDateString();

        $rows = CleaningNeighborhood::query()
            ->select(['id', 'city_name', 'name_ar', 'name_en', 'sort_order', 'is_active'])
            ->addSelect([
                'workers_count' => WorkerZone::query()
                    ->selectRaw('COUNT(DISTINCT worker_id)')
                    ->whereColumn('neighborhood_id', 'cleaning_neighborhoods.id')
                    ->where('is_active', true),
                'pending_bookings_count' => CleaningBooking::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('neighborhood_id', 'cleaning_neighborhoods.id')
                    ->whereBetween('scheduled_date', [$today, $endDate])
                    ->where('status', CleaningBookingStatus::Pending->value),
                'active_bookings_count' => CleaningBooking::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('neighborhood_id', 'cleaning_neighborhoods.id')
                    ->whereBetween('scheduled_date', [$today, $endDate])
                    ->whereIn('status', [
                        CleaningBookingStatus::WorkerAssigned->value,
                        CleaningBookingStatus::InProgress->value,
                    ]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get()
            ->map(function (CleaningNeighborhood $neighborhood) use ($thresholds): array {
                $workersCount = (int) ($neighborhood->workers_count ?? 0);
                $pendingBookingsCount = (int) ($neighborhood->pending_bookings_count ?? 0);
                $activeBookingsCount = (int) ($neighborhood->active_bookings_count ?? 0);
                $coverageLoad = $pendingBookingsCount + $activeBookingsCount;
                $coverageRatio = $workersCount > 0
                    ? round($coverageLoad / $workersCount, 1)
                    : ($coverageLoad > 0 ? (float) $coverageLoad : 0.0);

                $levelKey = 'ok';
                if ($workersCount === 0 && $coverageLoad > 0) {
                    $levelKey = 'uncovered';
                } elseif ($coverageRatio >= (float) ($thresholds['ok'] ?? 7)) {
                    $levelKey = 'high';
                } elseif ($coverageRatio <= (float) ($thresholds['low'] ?? 3)) {
                    $levelKey = 'low';
                }

                return [
                    'id' => $neighborhood->id,
                    'neighborhood' => $neighborhood->name_ar ?: $neighborhood->name_en,
                    'city_name' => $neighborhood->city_name,
                    'workers_count' => $workersCount,
                    'pending_bookings_count' => $pendingBookingsCount,
                    'active_bookings_count' => $activeBookingsCount,
                    'coverage_load' => $coverageLoad,
                    'coverage_ratio' => $coverageRatio,
                    'level_key' => $levelKey,
                    'level_label' => __('cleaning_admin.pages.geographic_coverage.levels.' . $levelKey),
                    'level_tone' => $this->mapLevelTone($levelKey),
                ];
            })
            ->values();

        $search = mb_strtolower(trim($this->search));
        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                return str_contains(mb_strtolower((string) $row['neighborhood']), $search)
                    || str_contains(mb_strtolower((string) $row['city_name']), $search);
            })->values();
        }

        if ($this->levelFilter !== 'all') {
            $rows = $rows->where('level_key', $this->levelFilter)->values();
        }

        $legacyZoneNameExpression = "COALESCE(NULLIF(worker_zones.name, ''), '-')";

        $legacyZones = WorkerZone::query()
            ->whereNull('neighborhood_id')
            ->selectRaw($legacyZoneNameExpression.' AS legacy_name')
            ->selectRaw('COUNT(*) AS zones_count')
            ->selectRaw('COUNT(DISTINCT worker_id) AS workers_count')
            ->groupByRaw($legacyZoneNameExpression)
            ->orderBy('legacy_name')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->legacy_name,
                'zones_count' => (int) $row->zones_count,
                'workers_count' => (int) $row->workers_count,
            ])
            ->values();

        return [
            'rows' => $rows,
            'legacyZones' => $legacyZones,
            'summary' => [
                'neighborhoods_count' => $rows->count(),
                'workers_count' => $rows->sum('workers_count'),
                'pending_bookings_count' => $rows->sum('pending_bookings_count'),
                'active_bookings_count' => $rows->sum('active_bookings_count'),
                'uncovered_count' => $rows->where('level_key', 'uncovered')->count(),
                'legacy_zones_count' => $legacyZones->count(),
            ],
            'filters' => [
                'dateRange' => [
                    '7' => __('cleaning_admin.filters.last_7_days'),
                    '14' => __('cleaning_admin.filters.last_14_days'),
                    '30' => __('cleaning_admin.filters.last_30_days'),
                ],
                'levels' => [
                    'all' => __('cleaning_admin.filters.all_levels'),
                    'uncovered' => __('cleaning_admin.pages.geographic_coverage.levels.uncovered'),
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
            'uncovered', 'high' => 'danger',
            'low' => 'success',
            default => 'info',
        };
    }
}
