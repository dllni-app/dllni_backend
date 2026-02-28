<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\Dispute;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use Filament\Pages\Page;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class CleaningOverview extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedHome;

    protected string $view = 'filament.cleaning-admin.pages.cleaning-overview';

    protected static ?string $navigationLabel = 'مركز القيادة المباشر';

    protected static ?string $title = 'مركز القيادة المباشر';

    protected static ?string $navigationGroup = 'مركز القيادة';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    public function getViewData(): array
    {
        return [
            'kpis' => [
                'cleaning_bookings' => CleaningBooking::query()->count(),
                'event_bookings' => EventBooking::query()->count(),
                'open_disputes' => Dispute::query()->whereIn('status', ['open', 'under_review'])->count(),
                'open_sos' => SosAlert::query()->where('status', '!=', 'resolved')->count(),
                'new_system_alerts' => SystemAlert::query()->where('status', 'new')->count(),
            ],
            'latestAlerts' => SystemAlert::query()->latest()->limit(10)->get(),
        ];
    }
}
