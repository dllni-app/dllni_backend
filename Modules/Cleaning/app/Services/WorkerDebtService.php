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
    public const ADMIN_LOAN_REFERENCE = 'admin_deposit_loan';

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
            throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.amount_positive'));
        }

        return DB::transaction(function () use ($worker, $amount, $reference, $notes, $createdByAdminId): CleaningDepositTransaction {
            CleaningWorkerDeposit::query()->firstOrCreate(
                ['worker_id' => $worker->id],
                [
                    'current_balance' => 0,
                    'debt_balance' => 0,
                    'deposited_total' => 0,
                    'withdrawn_total' => 0,
                    'admin_revenue_withdrawn_total' => 0,
                    'minimum_required' => 0,
                    'max_negative_balance' => $this->depositService->resolveLimits($worker)['maxNegativeBalance'],
                    'is_active' => true,
                ],
            );

            $account = CleaningWorkerDeposit::query()
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->firstOrFail();

            $depositBefore = max(0.0, (float) $account->current_balance);
            $indebtednessBefore = max(0.0, (float) $account->debt_balance);

            if ($depositBefore > 0) {
                throw new InvalidArgumentException(app()->isLocale('ar')
                    ? 'لا يمكن إضافة دين إداري للعامل طالما لديه رصيد إيداع قائم.'
                    : 'An administration loan cannot be added while the worker has an existing deposit balance.');
            }

            if ($indebtednessBefore > 0) {
                throw new InvalidArgumentException(app()->isLocale('ar')
                    ? 'يجب تسوية المديونية الحالية قبل إضافة دين إداري إلى رصيد الإيداع.'
                    : 'The current indebtedness must be settled before adding an administration loan to the deposit balance.');
            }

            $account->current_balance = $amount;
            $account->save();

            $transaction = CleaningDepositTransaction::query()->create([
                'worker_id' => $worker->id,
                'created_by_admin_id' => $createdByAdminId,
                'type' => 'debt',
                'amount' => $amount,
                'debt_settled_amount' => 0,
                'balance_before' => $depositBefore,
                'balance_after' => $amount,
                'debt_balance_before' => $indebtednessBefore,
                'debt_balance_after' => $indebtednessBefore,
                'reference' => $reference !== '' ? $reference : self::ADMIN_LOAN_REFERENCE,
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
        return $this->depositService->recordSettlement(
            worker: $worker,
            amount: $amount,
            reference: $reference,
            notes: $notes,
            createdByAdminId: $createdByAdminId,
        );
    }

    public function summary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $automaticPrefix = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';
        $legacyAutomaticPrefix = CleaningDepositTransaction::LEGACY_AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';
        $adminLoanReference = self::ADMIN_LOAN_REFERENCE.'%';

        $totals = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debt' AND reference LIKE ? THEN amount ELSE 0 END), 0) as manual_debt_total", [$adminLoanReference])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'refund' THEN debt_settled_amount ELSE 0 END), 0) as manual_debt_recovered")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('commission', 'admin_fee') OR (type = 'debt' AND (reference LIKE ? OR reference LIKE ?)) THEN amount ELSE 0 END), 0) as administration_due_total", [$automaticPrefix, $legacyAutomaticPrefix])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'settlement' THEN amount ELSE 0 END), 0) as settlement_total")
            ->first();

        $manualDebtTotal = max(0.0, (float) ($totals?->manual_debt_total ?? 0));
        $manualDebtRecovered = min($manualDebtTotal, max(0.0, (float) ($totals?->manual_debt_recovered ?? 0)));
        $manualDebtDue = max(0.0, $manualDebtTotal - $manualDebtRecovered);
        $administrationDueTotal = max(0.0, (float) ($totals?->administration_due_total ?? 0));
        $totalSettled = max(0.0, (float) ($totals?->settlement_total ?? 0));
        $indebtednessBalance = $this->indebtednessBalance($worker);

        return [
            'manualDebtTotal' => round($manualDebtTotal, 2),
            'manualDebtSettled' => round($manualDebtRecovered, 2),
            'manualDebtDue' => round($manualDebtDue, 2),
            'adminLoanBalance' => round($manualDebtDue, 2),
            'administrationDueTotal' => round($administrationDueTotal, 2),
            'administrationDueSettled' => round(min($administrationDueTotal, $totalSettled), 2),
            'administrationDue' => round($indebtednessBalance, 2),
            'indebtednessBalance' => round($indebtednessBalance, 2),
            'totalSettled' => round($totalSettled, 2),
            'outstandingAdministrationDue' => round($manualDebtDue + $indebtednessBalance, 2),
        ];
    }

    public function globalSummary(): array
    {
        $workers = Worker::query()->with('deposit')->get();
        $result = [
            'manualDebtTotal' => 0.0,
            'manualDebtSettled' => 0.0,
            'manualDebtDue' => 0.0,
            'adminLoanBalance' => 0.0,
            'administrationDueTotal' => 0.0,
            'administrationDueSettled' => 0.0,
            'administrationDue' => 0.0,
            'indebtednessBalance' => 0.0,
            'totalSettled' => 0.0,
            'outstandingAdministrationDue' => 0.0,
        ];

        foreach ($workers as $worker) {
            $summary = $this->summary($worker);
            foreach ($result as $key => $value) {
                $result[$key] = $value + (float) $summary[$key];
            }
        }

        return array_map(static fn (float $value): float => round($value, 2), $result);
    }

    public function outstandingAdministrationDue(Worker $worker): float
    {
        return (float) $this->summary($worker)['outstandingAdministrationDue'];
    }

    public function loanBalance(Worker $worker): float
    {
        return (float) $this->summary($worker)['adminLoanBalance'];
    }

    public function indebtednessBalance(Worker $worker): float
    {
        $worker->loadMissing('deposit');

        return round(max(0.0, (float) ($worker->deposit?->debt_balance ?? 0)), 2);
    }
}
