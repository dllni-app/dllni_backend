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
    /** @var array<int, array{label: string, value: string, description: string, tone: string}> */
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
     * @return array<int, array{label: string, value: string, description: string, tone: string}>
     */
    private function computeMetrics(): array
    {
        $summary = app(CleaningFinancialSummaryService::class)->global();

        return [
            [
                'label' => app()->isLocale('ar') ? 'إجمالي إيرادات الطلبات' : 'Total order revenue',
                'value' => $this->money((float) $summary['totalRevenue']),
                'description' => app()->isLocale('ar')
                    ? 'إجمالي القيمة المالية المسجلة للطلبات من حصص الخدمة والتنقل وهامش الإدارة.'
                    : 'Total recorded order value from service shares, travel fees, and administration margin.',
                'tone' => 'primary',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إجمالي أرصدة الإيداع الحالية' : 'Current deposit balances',
                'value' => $this->money((float) $summary['currentDepositBalance']),
                'description' => app()->isLocale('ar')
                    ? 'إجمالي المبالغ المودعة والمتبقية حالياً في أرصدة العمال بعد الخصومات والتسويات.'
                    : 'Total amount currently remaining in worker deposit balances after deductions and settlements.',
                'tone' => 'primary',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إجمالي المديونية الحالية' : 'Current debt balance',
                'value' => $this->money((float) $summary['currentDebtBalance']),
                'description' => app()->isLocale('ar')
                    ? 'إجمالي المبالغ المستحقة على العمال بعد نفاد رصيد الإيداع واستخدامهم من حد السماح.'
                    : 'Total amount currently owed by workers after their deposit is exhausted and allowance is used.',
                'tone' => (float) $summary['currentDebtBalance'] > 0 ? 'danger' : 'success',
            ],
            [
                'label' => app()->isLocale('ar') ? 'رصيد عمولة الإدارة الحالي' : 'Current administration commission',
                'value' => $this->money((float) $summary['currentAdminCommissionBalance']),
                'description' => app()->isLocale('ar')
                    ? 'عمولات الإدارة المسجلة التي لم يتم تحصيلها بعد بالسداد أو عند تصفير الحساب المالي.'
                    : 'Recorded administration commission that has not yet been collected by settlement or account closure.',
                'tone' => 'warning',
            ],
            [
                'label' => app()->isLocale('ar') ? 'إجمالي إيرادات الإدارة المحصلة' : 'Collected administration revenue',
                'value' => $this->money((float) $summary['collectedAdminRevenue']),
                'description' => app()->isLocale('ar')
                    ? 'إجمالي عمولات الإدارة التي تم تحصيلها فعلياً، سواء بسداد مديونية حد السماح أو عند تصفير الحساب المالي.'
                    : 'Total administration commission actually collected through allowance-debt settlements or financial account closure.',
                'tone' => 'success',
            ],
        ];
    }

    private function money(float $value): string
    {
        return AdminUiFormatter::formatCurrency($value, 0, 'SYP');
    }
}
