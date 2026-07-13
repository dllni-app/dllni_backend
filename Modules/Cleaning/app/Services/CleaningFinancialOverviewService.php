<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\UserModuleType;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CleaningFinancialOverviewService
{
    public function __construct(
        private readonly WorkerDebtService $workerDebtService,
    ) {}

    /**
     * @return array{
     *     currentDebt: float,
     *     totalDeposits: float,
     *     totalSettlements: float,
     *     totalRefunds: float
     * }
     */
    public function transactionMetrics(): array
    {
        $ledger = $this->workerDebtService->globalSummary();
        $deposits = $this->depositTotals();

        return [
            'currentDebt' => round((float) $ledger['outstandingAdministrationDue'], 2),
            'totalDeposits' => round($deposits['depositedTotal'], 2),
            'totalSettlements' => round((float) $ledger['totalSettled'], 2),
            'totalRefunds' => round($deposits['refundedTotal'], 2),
        ];
    }

    /**
     * @return array{
     *     depositsHeld: float,
     *     outstandingAdministrationDue: float,
     *     settlementsReceived: float,
     *     depositRefunds: float,
     *     activeWorkers: int,
     *     restrictedWorkers: int
     * }
     */
    public function reportMetrics(): array
    {
        $transactions = $this->transactionMetrics();
        $deposits = $this->depositTotals();

        return [
            'depositsHeld' => round(max(0.0, $deposits['depositedTotal'] - $deposits['refundedTotal']), 2),
            'outstandingAdministrationDue' => $transactions['currentDebt'],
            'settlementsReceived' => $transactions['totalSettlements'],
            'depositRefunds' => $transactions['totalRefunds'],
            'activeWorkers' => $this->cleaningWorkersQuery()->activeAvailable()->count(),
            'restrictedWorkers' => $this->cleaningWorkersQuery()->restricted()->count(),
        ];
    }

    /**
     * The deposit account aggregates are the source of truth for deposited and
     * refunded principal. Historical databases may contain account totals that
     * predate the transaction ledger, so summing ledger rows can under-report
     * these two cards.
     *
     * @return array{depositedTotal: float, refundedTotal: float}
     */
    private function depositTotals(): array
    {
        $totals = CleaningWorkerDeposit::query()
            ->whereHas('worker.user', fn (Builder $query): Builder => $query->where(
                'module_type',
                UserModuleType::CleaningWorker->value,
            ))
            ->selectRaw('COALESCE(SUM(deposited_total), 0) as deposited_total')
            ->selectRaw('COALESCE(SUM(withdrawn_total), 0) as refunded_total')
            ->first();

        return [
            'depositedTotal' => (float) ($totals?->deposited_total ?? 0),
            'refundedTotal' => (float) ($totals?->refunded_total ?? 0),
        ];
    }

    private function cleaningWorkersQuery(): Builder
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where(
                'module_type',
                UserModuleType::CleaningWorker->value,
            ));
    }
}
