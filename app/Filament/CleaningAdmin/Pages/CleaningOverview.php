<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Enums\AlertType;
use App\Models\Dispute;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use UnitEnum;

final class CleaningOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedHome;

    protected string $view = 'filament.cleaning-admin.pages.cleaning-overview';

    protected static ?string $navigationLabel = 'مركز القيادة المباشر';

    protected static ?string $title = 'مركز القيادة المباشر';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationTooltip(): ?string
    {
        return 'نظرة عامة حية: مؤشرات الأداء، حجوزات اليوم، النزاعات، تنبيهات الاستغاثة وتنبيهات النظام مع إجراءات سريعة.';
    }

    public static function alertTypeLabels(): array
    {
        return [
            AlertType::DelayedRating->value => 'تأخر التقييم المتبادل',
            AlertType::FrozenGPS->value => 'تجمد الموقع',
            AlertType::SOSTriggered->value => 'SOS',
            AlertType::TimeExpired->value => 'تجاوز وقت العمل',
            AlertType::OverdueCompletion->value => 'تجاوز وقت العمل دون إجراء',
            AlertType::AnomalyDetected->value => 'شذوذ',
        ];
    }

    public function getSubheading(): ?string
    {
        return 'نظرة عامة حية على مؤشرات الأداء، حجوزات اليوم، النزاعات المفتوحة، تنبيهات الاستغاثة وتنبيهات النظام مع إجراءات سريعة للاتصال أو الحل.';
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
            'kpis' => [
                'cleaning_bookings' => CleaningBooking::query()->count(),
                'event_bookings' => EventBooking::query()->count(),
                'open_disputes' => Dispute::query()->whereIn('status', ['open', 'under_review'])->count(),
                'open_sos' => SosAlert::query()->where('status', '!=', 'resolved')->count(),
                'new_system_alerts' => SystemAlert::query()->where('status', 'new')->count(),
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
            Notification::make()->title('تم حل التنبيه')->success()->send();
        }
    }
}
