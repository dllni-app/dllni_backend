<?php

declare(strict_types=1);

namespace App\Filament\Company\Pages;

use App\Enums\PermissionGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\DeliveryReportService;

final class DeliveryReportsPage extends Page
{
    public int $periodDays = 30;

    /** @var array<string, mixed> */
    public array $report = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.company.pages.delivery-reports';

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.reports.nav_label');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryReports->value.'.view') ?? false;
    }

    public function mount(): void
    {
        $this->loadReport();
    }

    public function updatedPeriodDays(): void
    {
        $this->loadReport();
    }

    public function getTitle(): string|Htmlable
    {
        return __('delivery_company.reports.title');
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return [
            '7' => __('delivery_company.reports.filters.last_7_days'),
            '30' => __('delivery_company.reports.filters.last_30_days'),
            '90' => __('delivery_company.reports.filters.last_90_days'),
        ];
    }

    public function statusLabel(string $status): string
    {
        return __('delivery_company.orders.enums.status.'.$status);
    }

    private function loadReport(): void
    {
        $company = app(DeliveryCompanyContextService::class)->resolveFromUser(auth()->user());
        $days = max(7, min((int) $this->periodDays, 90));

        $this->report = app(DeliveryReportService::class)->summary($company, $days);
    }
}
