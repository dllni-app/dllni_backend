<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AlertType;
use App\Models\Dispute;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

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
            || $user->can('system_alerts.view');
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
        $allAlerts = SystemAlert::query()
            ->with(['booking' => fn ($q) => $q->with(['customer', 'worker.user'])])
            ->latest()
            ->limit(20)
            ->get();
        $sosAlerts = $allAlerts->filter(fn ($a) => $a->alert_type?->value === AlertType::SOSTriggered->value);
        $otherAlerts = $allAlerts->filter(fn ($a) => $a->alert_type?->value !== AlertType::SOSTriggered->value);

        return [
            'overviewKpis' => [
                [
                    'label' => __('cleaning_admin.overview.kpis.cleaning_bookings'),
                    'value' => CleaningBooking::query()->count(),
                ],
                [
                    'label' => __('cleaning_admin.overview.kpis.event_bookings'),
                    'value' => EventBooking::query()->count(),
                ],
                [
                    'label' => __('cleaning_admin.overview.kpis.open_disputes'),
                    'value' => Dispute::query()->whereIn('status', ['open', 'under_review'])->count(),
                ],
                [
                    'label' => __('cleaning_admin.overview.kpis.open_sos'),
                    'value' => SosAlert::query()->where('status', '!=', 'resolved')->count(),
                ],
                [
                    'label' => __('cleaning_admin.overview.kpis.new_system_alerts'),
                    'value' => SystemAlert::query()->where('status', 'new')->count(),
                ],
            ],
            'sosAlerts' => $sosAlerts,
            'otherAlerts' => $otherAlerts,
            'alertTypeLabels' => self::alertTypeLabels(),
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
