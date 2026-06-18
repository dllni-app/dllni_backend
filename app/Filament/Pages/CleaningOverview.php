<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DisputeStatus;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\EventBookings\EventBookingResource;
use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Models\Dispute;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Models\CleaningBanner;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\EventBooking;
use Modules\Resturants\Models\Order;

final class CleaningOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedHome;

    protected string $view = 'filament.cleaning-admin.pages.cleaning-overview';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.overview.title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.overview.tooltip');
    }

    public static function alertTypeLabels(): array
    {
        return [
            AlertType::DelayedRating->value => __('cleaning_admin.alert_types.delayed_rating'),
            AlertType::FrozenGPS->value => __('cleaning_admin.alert_types.frozen_gps'),
            AlertType::SOSTriggered->value => __('cleaning_admin.alert_types.sos'),
            AlertType::TimeExpired->value => __('cleaning_admin.alert_types.time_expired'),
            AlertType::OverdueCompletion->value => __('cleaning_admin.alert_types.overdue_completion'),
            AlertType::AnomalyDetected->value => __('cleaning_admin.alert_types.anomaly'),
        ];
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

        return $user->can('bookings.view')
            || $user->can('workers.view')
            || $user->can('disputes.view')
            || $user->can('system_alerts.view')
            || $user->can('banners.view');
    }

    public function getTitle(): string
    {
        return __('cleaning_admin.overview.title');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.overview.subheading');
    }

    public function getViewData(): array
    {
        $today = now()->toDateString();

        $cleaningBookingsCount = CleaningBooking::query()->count();
        $eventBookingsCount = EventBooking::query()->count();
        $openDisputesCount = Dispute::query()
            ->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::UnderReview->value])
            ->count();
        $openSosCount = SosAlert::query()
            ->where('status', '!=', SOSStatus::Resolved->value)
            ->count();
        $newSystemAlertsCount = SystemAlert::query()
            ->where('status', SystemAlertStatus::New->value)
            ->count();
        $visibleBannersCount = CleaningBanner::query()->visibleNow()->count();

        $overviewKpis = [
            [
                'label' => __('cleaning_admin.overview.kpis.cleaning_bookings'),
                'value' => $cleaningBookingsCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.cleaning_bookings'),
                'icon' => 'heroicon-o-calendar-days',
                'tone' => 'primary',
                'url' => CleaningBookingResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.kpis.event_bookings'),
                'value' => $eventBookingsCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.event_bookings'),
                'icon' => 'heroicon-o-calendar',
                'tone' => 'success',
                'url' => EventBookingResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.kpis.open_disputes'),
                'value' => $openDisputesCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.open_disputes'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'tone' => 'warning',
                'url' => DisputeResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.kpis.open_sos'),
                'value' => $openSosCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.open_sos'),
                'icon' => 'heroicon-o-phone',
                'tone' => 'danger',
            ],
            [
                'label' => __('cleaning_admin.overview.kpis.new_system_alerts'),
                'value' => $newSystemAlertsCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.new_system_alerts'),
                'icon' => 'heroicon-o-bell-alert',
                'tone' => 'info',
                'url' => SystemAlertResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.kpis.active_banners'),
                'value' => $visibleBannersCount,
                'hint' => __('cleaning_admin.overview.kpi_hints.active_banners'),
                'icon' => 'heroicon-o-photo',
                'tone' => 'primary',
                'url' => CleaningBannerResource::getUrl('index'),
            ],
        ];

        $workloadSummary = [
            [
                'label' => __('cleaning_admin.overview.workload.today_cleaning'),
                'value' => CleaningBooking::query()->whereDate('scheduled_date', $today)->count(),
                'description' => __('cleaning_admin.overview.workload.today_cleaning_hint'),
                'icon' => 'heroicon-o-calendar-days',
                'tone' => 'primary',
            ],
            [
                'label' => __('cleaning_admin.overview.workload.waiting_assignment'),
                'value' => CleaningBooking::query()->where('status', CleaningBookingStatus::Pending->value)->count(),
                'description' => __('cleaning_admin.overview.workload.waiting_assignment_hint'),
                'icon' => 'heroicon-o-user-plus',
                'tone' => 'warning',
            ],
            [
                'label' => __('cleaning_admin.overview.workload.searching_team'),
                'value' => CleaningBooking::query()
                    ->where('status', CleaningBookingStatus::Pending->value)
                    ->whereHas('acceptedWorkerAssignments')
                    ->count(),
                'description' => __('cleaning_admin.overview.workload.searching_team_hint'),
                'icon' => 'heroicon-o-users',
                'tone' => 'info',
            ],
            [
                'label' => __('cleaning_admin.overview.workload.active_jobs'),
                'value' => CleaningBooking::query()
                    ->whereIn('status', [
                        CleaningBookingStatus::WorkerAssigned->value,
                        CleaningBookingStatus::AwaitingStartVerification->value,
                        CleaningBookingStatus::InProgress->value,
                        CleaningBookingStatus::AwaitingCustomerCompletion->value,
                        CleaningBookingStatus::TimeExtensionRequested->value,
                    ])
                    ->count(),
                'description' => __('cleaning_admin.overview.workload.active_jobs_hint'),
                'icon' => 'heroicon-o-bolt',
                'tone' => 'success',
            ],
            [
                'label' => __('cleaning_admin.overview.workload.unassigned_rooms'),
                'value' => CleaningBookingRoom::query()->whereNull('assigned_worker_id')->count(),
                'description' => __('cleaning_admin.overview.workload.unassigned_rooms_hint'),
                'icon' => 'heroicon-o-square-3-stack-3d',
                'tone' => 'gray',
            ],
            [
                'label' => __('cleaning_admin.overview.workload.pending_events'),
                'value' => EventBooking::query()
                    ->whereIn('status', [EventBookingStatus::Pending->value, EventBookingStatus::Confirmed->value])
                    ->count(),
                'description' => __('cleaning_admin.overview.workload.pending_events_hint'),
                'icon' => 'heroicon-o-sparkles',
                'tone' => 'info',
            ],
        ];

        $quickActions = [
            [
                'label' => __('cleaning_admin.overview.actions.review_bookings'),
                'description' => __('cleaning_admin.overview.actions.review_bookings_hint'),
                'icon' => 'heroicon-o-calendar-days',
                'url' => CleaningBookingResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.actions.handle_disputes'),
                'description' => __('cleaning_admin.overview.actions.handle_disputes_hint'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'url' => DisputeResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.actions.review_alerts'),
                'description' => __('cleaning_admin.overview.actions.review_alerts_hint'),
                'icon' => 'heroicon-o-bell-alert',
                'url' => SystemAlertResource::getUrl('index'),
            ],
            [
                'label' => __('cleaning_admin.overview.actions.manage_banners'),
                'description' => __('cleaning_admin.overview.actions.manage_banners_hint'),
                'icon' => 'heroicon-o-photo',
                'url' => CleaningBannerResource::getUrl('index'),
            ],
        ];

        $allAlerts = SystemAlert::query()
            ->with(['booking' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    CleaningBooking::class => ['customer', 'worker.user'],
                    EventBooking::class => ['customer'],
                    Order::class => ['customer'],
                ]);
            }])
            ->latest()
            ->limit(20)
            ->get();
        $sosAlerts = $allAlerts->filter(fn ($a) => $a->alert_type?->value === AlertType::SOSTriggered->value);
        $otherAlerts = $allAlerts->filter(fn ($a) => $a->alert_type?->value !== AlertType::SOSTriggered->value);

        $cleaningStatusBreakdown = $this->buildBreakdown(
            CleaningBooking::query()
                ->toBase()
                ->selectRaw('status as key_name, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'key_name')
                ->all(),
            fn (string $key): string => $this->translateEnumValue(
                'cleaning_admin.enums.cleaning_booking_status.',
                $key,
            ),
        );

        $systemAlertStatusBreakdown = $this->buildBreakdown(
            SystemAlert::query()
                ->toBase()
                ->selectRaw('status as key_name, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'key_name')
                ->all(),
            fn (string $key): string => $this->translateEnumValue(
                'cleaning_admin.enums.system_alert_status.',
                $key,
            ),
        );

        $alertTypeLabels = self::alertTypeLabels();
        $alertTypeBreakdown = $this->buildBreakdown(
            SystemAlert::query()
                ->where('created_at', '>=', now()->subDays(7))
                ->toBase()
                ->selectRaw('alert_type as key_name, COUNT(*) as total')
                ->groupBy('alert_type')
                ->pluck('total', 'key_name')
                ->all(),
            fn (string $key): string => $alertTypeLabels[$key] ?? Str::headline($key),
        );

        $activityTrend = $this->buildSevenDayActivity();

        return [
            'overviewKpis' => $overviewKpis,
            'workloadSummary' => $workloadSummary,
            'quickActions' => $quickActions,
            'activityTrend' => $activityTrend,
            'cleaningStatusBreakdown' => $cleaningStatusBreakdown,
            'systemAlertStatusBreakdown' => $systemAlertStatusBreakdown,
            'alertTypeBreakdown' => $alertTypeBreakdown,
            'sosAlerts' => $sosAlerts,
            'otherAlerts' => $otherAlerts,
            'alertTypeLabels' => $alertTypeLabels,
        ];
    }

    private function translateEnumValue(string $translationPrefix, string $key): string
    {
        $translationKey = $translationPrefix . $key;
        $translated = __($translationKey);

        if ($translated === $translationKey) {
            return Str::of($key)->replace('_', ' ')->title()->toString();
        }

        return $translated;
    }

    /**
     * @param  array<string, int|string>  $rawCounts
     * @return array{
     *   total:int,
     *   max:int,
     *   items:array<int, array{key:string,label:string,value:int,share:float,width:float,color:string}>
     * }
     */
    private function buildBreakdown(array $rawCounts, callable $labelResolver): array
    {
        $palette = [
            'bg-primary-500',
            'bg-success-500',
            'bg-warning-500',
            'bg-danger-500',
            'bg-info-500',
            'bg-gray-500',
        ];

        $items = collect($rawCounts)
            ->map(function (mixed $value, mixed $key): array {
                return [
                    'key' => (string) $key,
                    'value' => (int) $value,
                ];
            })
            ->filter(fn (array $item): bool => $item['value'] > 0)
            ->sortByDesc('value')
            ->values();

        $total = (int) $items->sum('value');
        $max = (int) max(1, $items->max('value') ?? 1);

        return [
            'total' => $total,
            'max' => $max,
            'items' => $items->values()->map(
                function (array $item, int $index) use ($labelResolver, $total, $max, $palette): array {
                    $value = $item['value'];

                    return [
                        'key' => $item['key'],
                        'label' => $labelResolver($item['key']),
                        'value' => $value,
                        'share' => round(($value / max(1, $total)) * 100, 1),
                        'width' => round(($value / max(1, $max)) * 100, 1),
                        'color' => $palette[$index % count($palette)],
                    ];
                },
            )->all(),
        ];
    }

    /**
     * @return array{
     *   max:int,
     *   days:array<int, array{date:string,label:string,bookings:int,alerts:int}>
     * }
     */
    private function buildSevenDayActivity(): array
    {
        $startDate = now()->subDays(6)->startOfDay();

        $bookingCountsByDay = CleaningBooking::query()
            ->toBase()
            ->selectRaw('DATE(created_at) as day_key, COUNT(*) as total')
            ->where('created_at', '>=', $startDate)
            ->groupBy('day_key')
            ->pluck('total', 'day_key');

        $alertCountsByDay = SystemAlert::query()
            ->toBase()
            ->selectRaw('DATE(created_at) as day_key, COUNT(*) as total')
            ->where('created_at', '>=', $startDate)
            ->groupBy('day_key')
            ->pluck('total', 'day_key');

        $days = Collection::times(7, function (int $offset) use ($startDate, $bookingCountsByDay, $alertCountsByDay): array {
            $date = Carbon::parse($startDate)->addDays($offset);
            $dateKey = $date->toDateString();

            return [
                'date' => $dateKey,
                'label' => $date->locale(app()->getLocale())->isoFormat('ddd'),
                'bookings' => (int) ($bookingCountsByDay[$dateKey] ?? 0),
                'alerts' => (int) ($alertCountsByDay[$dateKey] ?? 0),
            ];
        });

        $maxBookings = (int) $days->max('bookings');
        $maxAlerts = (int) $days->max('alerts');

        return [
            'max' => max(1, $maxBookings, $maxAlerts),
            'days' => $days->all(),
        ];
    }

    public function resolveAlert(int $id): void
    {
        $alert = SystemAlert::query()->find($id);
        if ($alert) {
            $alert->update(['status' => \App\Enums\SystemAlertStatus::Resolved]);
            Notification::make()->title(__('cleaning_admin.overview_alerts.resolved'))->success()->send();
        }
    }
}
