<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\AdminUiFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Cleaning\Services\CleaningFinancialOverviewService;

final class CleaningFinancialReport extends Page
{
    /** @var array<int, array{label: string, value: string, tone: string}> */
    public array $metrics = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.cleaning-admin.pages.financial-report';

    protected static ?int $navigationSort = 53;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.report.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_finance_guidance.report_page_subtitle');
    }

    public function getTitle(): string
    {
        return __('cleaning_admin.report.title');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_finance_guidance.report_page_subtitle');
    }

    public function mount(): void
    {
        $this->metrics = $this->computeMetrics();
    }

    /**
     * @return array<int, array{label: string, value: string, tone: string}>
     */
    private function computeMetrics(): array
    {
        $metrics = app(CleaningFinancialOverviewService::class)->reportMetrics();

        return [
            ['label' => __('cleaning_admin.report.metrics.deposits_held'), 'value' => $this->money($metrics['depositsHeld']), 'tone' => 'primary'],
            ['label' => __('cleaning_finance.report.outstanding_admin_due'), 'value' => $this->money($metrics['outstandingAdministrationDue']), 'tone' => 'danger'],
            ['label' => __('cleaning_admin.report.metrics.settlements_received'), 'value' => $this->money($metrics['settlementsReceived']), 'tone' => 'success'],
            ['label' => __('cleaning_admin.report.metrics.deposit_refunds'), 'value' => $this->money($metrics['depositRefunds']), 'tone' => 'warning'],
            ['label' => __('cleaning_admin.report.metrics.active_workers'), 'value' => AdminUiFormatter::formatNumber($metrics['activeWorkers']), 'tone' => 'success'],
            ['label' => __('cleaning_admin.report.metrics.restricted_workers'), 'value' => AdminUiFormatter::formatNumber($metrics['restrictedWorkers']), 'tone' => 'danger'],
        ];
    }

    private function money(float $value): string
    {
        return AdminUiFormatter::formatCurrency($value, 0);
    }
}
