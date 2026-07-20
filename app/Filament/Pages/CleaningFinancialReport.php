<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\AdminUiFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Cleaning\Services\CleaningFinancialSummaryService;

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
        return app()->isLocale('ar')
            ? 'ملخص القيم المالية الحالية وإيرادات الإدارة.'
            : 'Summary of current financial balances and administration revenue.';
    }

    public function getTitle(): string
    {
        return __('cleaning_admin.report.title');
    }

    public function getSubheading(): ?string
    {
        return app()->isLocale('ar')
            ? 'يعرض التقرير الإيرادات والأرصدة الحالية فقط وفق دورة الإيداع والمديونية وتصفير الحساب.'
            : 'This report shows only revenue and current balances according to the deposit, debt, and account-settlement lifecycle.';
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
        $summary = app(CleaningFinancialSummaryService::class)->global();

        return [
            [
                'label' => app()->isLocale('ar') ? 'إجمالي إيرادات الطلبات' : 'Total order revenue',
                'value' => $this->money((float) $summary['totalRevenue']),
                'tone' => 'primary',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إجمالي أرصدة الإيداع الحالية' : 'Current deposit balances',
                'value' => $this->money((float) $summary['currentDepositBalance']),
                'tone' => 'primary',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إجمالي المديونية الحالية' : 'Current debt balance',
                'value' => $this->money((float) $summary['currentDebtBalance']),
                'tone' => (float) $summary['currentDebtBalance'] > 0 ? 'danger' : 'success',
            ],
            [
                'label' => app()->isLocale('ar') ? 'رصيد عمولة الإدارة الحالي' : 'Current administration commission',
                'value' => $this->money((float) $summary['currentAdminCommissionBalance']),
                'tone' => 'warning',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إيرادات الإدارة المسحوبة' : 'Withdrawn administration revenue',
                'value' => $this->money((float) $summary['withdrawnAdminRevenue']),
                'tone' => 'success',
            ],
        ];
    }

    private function money(float $value): string
    {
        return AdminUiFormatter::formatCurrency($value, 0, 'SYP');
    }
}
