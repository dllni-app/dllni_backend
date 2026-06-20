<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

final class CleaningFinancialReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.cleaning-admin.pages.financial-report';

    protected static ?int $navigationSort = 53;

    /** @var array<int, array{label: string, value: string, tone: string}> */
    public array $metrics = [];

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.report.nav_label');
    }

    public function getTitle(): string
    {
        return __('cleaning_admin.report.title');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.report.subtitle');
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
        $sums = CleaningDepositTransaction::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'admin_fee' THEN amount ELSE 0 END), 0) as commissions")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlements")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('refund', 'withdrawal') THEN amount ELSE 0 END), 0) as refunds")
            ->first();

        $commissions = (float) ($sums?->commissions ?? 0);
        $settlements = (float) ($sums?->settlements ?? 0);
        $refunds = (float) ($sums?->refunds ?? 0);
        $outstanding = max(0.0, $commissions - $settlements);

        $depositsHeld = (float) CleaningWorkerDeposit::query()
            ->sum(DB::raw('COALESCE(deposited_total, 0) - COALESCE(withdrawn_total, 0)'));

        $restrictedWorkers = Worker::query()
            ->where('security_deposit_status', 'insufficient_balance')
            ->count();

        $activeWorkers = Worker::query()
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->where(function ($query): void {
                $query->whereNull('security_deposit_status')
                    ->orWhere('security_deposit_status', 'active');
            })
            ->count();

        return [
            ['label' => __('cleaning_admin.report.metrics.deposits_held'), 'value' => $this->money($depositsHeld), 'tone' => 'primary'],
            ['label' => __('cleaning_admin.report.metrics.outstanding_commissions'), 'value' => $this->money($outstanding), 'tone' => 'danger'],
            ['label' => __('cleaning_admin.report.metrics.settlements_received'), 'value' => $this->money($settlements), 'tone' => 'success'],
            ['label' => __('cleaning_admin.report.metrics.deposit_refunds'), 'value' => $this->money($refunds), 'tone' => 'warning'],
            ['label' => __('cleaning_admin.report.metrics.active_workers'), 'value' => (string) $activeWorkers, 'tone' => 'success'],
            ['label' => __('cleaning_admin.report.metrics.restricted_workers'), 'value' => (string) $restrictedWorkers, 'tone' => 'danger'],
        ];
    }

    private function money(float $value): string
    {
        return 'SYP '.number_format($value, 2);
    }
}
