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
use Modules\Cleaning\Services\WorkerDebtService;

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
        $ledger = app(WorkerDebtService::class)->globalSummary();

        $refunds = (float) CleaningDepositTransaction::query()
            ->whereIn('type', ['refund', 'withdrawal'])
            ->sum('amount');

        $depositsHeld = (float) CleaningWorkerDeposit::query()
            ->sum(DB::raw('COALESCE(deposited_total, 0) - COALESCE(withdrawn_total, 0)'));

        $activeWorkers = Worker::query()->activeAvailable()->count();
        $restrictedWorkers = Worker::query()->restricted()->count();

        return [
            ['label' => __('cleaning_admin.report.metrics.deposits_held'), 'value' => $this->money($depositsHeld), 'tone' => 'primary'],
            ['label' => __('cleaning_finance.report.outstanding_admin_due'), 'value' => $this->money((float) $ledger['outstandingAdministrationDue']), 'tone' => 'danger'],
            ['label' => __('cleaning_admin.report.metrics.settlements_received'), 'value' => $this->money((float) $ledger['totalSettled']), 'tone' => 'success'],
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
