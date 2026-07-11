<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WorkerDebtService
{
    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function recordDebt(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null,
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Debt amount must be greater than zero.');
        }

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $deposit = $this->lockOrCreateDeposit($worker);
            $balanceBefore = (float) $deposit->current_balance;
            $balanceAfter = $balanceBefore + $amount;

            $deposit->update(['current_balance' => $balanceAfter]);

            $transaction = CleaningDepositTransaction::query()->create([
                'worker_id' => $worker->id,
                'created_by_admin_id' => $createdByAdminId,
                'type' => 'debt',
                'amount' => $amount,
                'debt_settled_amount' => 0,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => $notes,
            ]);

            $this->depositService->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    public function recordSettlement(
        Worker $worker,
        float $amount,
        string $reference,
        ?string $notes = null,
        ?int $createdByAdminId = null,
    ): CleaningDepositTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Settlement amount must be greater than zero.');
        }

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            $deposit = $this->lockOrCreateDeposit($worker);
            $summary = $this->summary($worker);
            $debtSettledAmount = min($amount, (float) $summary['manualDebtDue']);

            $balanceBefore = (float) $deposit->current_balance;

            // Paying administration-funded debt removes the temporary credit from
            // the worker balance. Any remainder settles commission (or remains a
            // legacy prepayment) and therefore credits the deposit balance.
            $balanceDelta = $amount - (2 * $debtSettledAmount);
            $balanceAfter = $balanceBefore + $balanceDelta;

            $deposit->update(['current_balance' => $balanceAfter]);

            $transaction = CleaningDepositTransaction::query()->create([
                'worker_id' => $worker->id,
                'created_by_admin_id' => $createdByAdminId,
                'type' => 'settlement',
                'amount' => $amount,
                'debt_settled_amount' => $debtSettledAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => $notes,
            ]);

            $this->depositService->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    /**
     * @return array{
     *     manualDebtTotal: float,
     *     manualDebtSettled: float,
     *     manualDebtDue: float,
     *     adminFeeTotal: float,
     *     adminFeeSettled: float,
     *     adminFeeDue: float,
     *     totalSettled: float,
     *     outstandingAdministrationDue: float
     * }
     */
    public function summary(Worker $worker): array
    {
        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debt' THEN amount ELSE 0 END), 0) as debt_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'admin_fee' THEN amount ELSE 0 END), 0) as admin_fee_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN debt_settled_amount ELSE 0 END), 0) as debt_settled_total")
            ->first();

        return $this->buildSummary(
            debtTotal: (float) ($totals?->debt_total ?? 0),
            adminFeeTotal: (float) ($totals?->admin_fee_total ?? 0),
            settlementTotal: (float) ($totals?->settlement_total ?? 0),
            debtSettledTotal: (float) ($totals?->debt_settled_total ?? 0),
        );
    }

    /**
     * @return array{
     *     manualDebtTotal: float,
     *     manualDebtSettled: float,
     *     manualDebtDue: float,
     *     adminFeeTotal: float,
     *     adminFeeSettled: float,
     *     adminFeeDue: float,
     *     totalSettled: float,
     *     outstandingAdministrationDue: float
     * }
     */
    public function globalSummary(): array
    {
        $totals = CleaningDepositTransaction::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debt' THEN amount ELSE 0 END), 0) as debt_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'admin_fee' THEN amount ELSE 0 END), 0) as admin_fee_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN debt_settled_amount ELSE 0 END), 0) as debt_settled_total")
            ->first();

        return $this->buildSummary(
            debtTotal: (float) ($totals?->debt_total ?? 0),
            adminFeeTotal: (float) ($totals?->admin_fee_total ?? 0),
            settlementTotal: (float) ($totals?->settlement_total ?? 0),
            debtSettledTotal: (float) ($totals?->debt_settled_total ?? 0),
        );
    }

    public function outstandingAdministrationDue(Worker $worker): float
    {
        return (float) $this->summary($worker)['outstandingAdministrationDue'];
    }

    private function lockOrCreateDeposit(Worker $worker): CleaningWorkerDeposit
    {
        $deposit = CleaningWorkerDeposit::query()
            ->where('worker_id', $worker->id)
            ->lockForUpdate()
            ->first();

        if ($deposit instanceof CleaningWorkerDeposit) {
            return $deposit;
        }

        $worker->loadMissing('deposit');
        $limits = $this->depositService->resolveLimits($worker);

        $created = CleaningWorkerDeposit::query()->create([
            'worker_id' => $worker->id,
            'current_balance' => 0,
            'deposited_total' => 0,
            'withdrawn_total' => 0,
            'minimum_required' => $limits['minimumRequired'],
            'max_negative_balance' => $limits['maxNegativeBalance'],
            'is_active' => true,
        ]);

        return CleaningWorkerDeposit::query()
            ->whereKey($created->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{
     *     manualDebtTotal: float,
     *     manualDebtSettled: float,
     *     manualDebtDue: float,
     *     adminFeeTotal: float,
     *     adminFeeSettled: float,
     *     adminFeeDue: float,
     *     totalSettled: float,
     *     outstandingAdministrationDue: float
     * }
     */
    private function buildSummary(
        float $debtTotal,
        float $adminFeeTotal,
        float $settlementTotal,
        float $debtSettledTotal,
    ): array {
        $manualDebtSettled = min($debtTotal, $debtSettledTotal);
        $adminFeeSettled = max(0.0, $settlementTotal - $manualDebtSettled);
        $manualDebtDue = max(0.0, $debtTotal - $manualDebtSettled);
        $adminFeeDue = max(0.0, $adminFeeTotal - $adminFeeSettled);

        return [
            'manualDebtTotal' => round($debtTotal, 2),
            'manualDebtSettled' => round($manualDebtSettled, 2),
            'manualDebtDue' => round($manualDebtDue, 2),
            'adminFeeTotal' => round($adminFeeTotal, 2),
            'adminFeeSettled' => round($adminFeeSettled, 2),
            'adminFeeDue' => round($adminFeeDue, 2),
            'totalSettled' => round($settlementTotal, 2),
            'outstandingAdministrationDue' => round($manualDebtDue + $adminFeeDue, 2),
        ];
    }
}
