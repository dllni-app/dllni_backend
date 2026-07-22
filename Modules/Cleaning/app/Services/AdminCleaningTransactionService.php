<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class AdminCleaningTransactionService
{
    public const TYPES = ['deposit', 'debt', 'refund'];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
        private readonly WorkerOrderSolvencyService $solvencyService,
    ) {}

    public function findWorker(int $workerId): Worker
    {
        $worker = Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->with('deposit')
            ->find($workerId);

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.worker_required'));
        }

        return $worker;
    }

    public function snapshot(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $financial = $this->depositService->financialSummary($worker);
        $debt = $this->debtService->summary($worker);
        $capacity = $this->solvencyService->workerCapacitySummary($worker);
        $limits = $this->depositService->resolveLimits($worker);
        $currentBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $indebtednessBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));
        $adminLoanBalance = max(0.0, (float) ($debt['adminLoanBalance'] ?? 0));
        $activeReservedCommission = (float) ($capacity['activeReservedCommission'] ?? 0);
        $withdrawnAdminRevenue = max(0.0, (float) ($worker->deposit?->admin_revenue_withdrawn_total ?? 0));
        $totalCommission = max(0.0, (float) ($financial['totalCommission'] ?? 0));
        $adminCommissionBalance = max(0.0, $totalCommission - $withdrawnAdminRevenue);
        $maxRefundable = $indebtednessBalance <= 0 && $activeReservedCommission <= 0
            ? max(0.0, $currentBalance - $adminLoanBalance)
            : 0.0;

        return [
            'currentBalance' => round($currentBalance, 2),
            'depositBalance' => round($currentBalance, 2),
            'adminLoanBalance' => round($adminLoanBalance, 2),
            'loanBalance' => round($adminLoanBalance, 2),
            'hasAdminLoan' => $adminLoanBalance > 0,
            'debtBalance' => round($indebtednessBalance, 2),
            'indebtednessBalance' => round($indebtednessBalance, 2),
            'depositedTotal' => round((float) ($worker->deposit?->deposited_total ?? 0), 2),
            'withdrawnTotal' => round((float) ($worker->deposit?->withdrawn_total ?? 0), 2),
            'minimumRequired' => 0.0,
            'maxNegativeBalance' => round((float) $limits['maxNegativeBalance'], 2),
            'allowedDebtLimit' => round((float) $limits['maxNegativeBalance'], 2),
            'remainingDebtCapacity' => round((float) ($capacity['remainingDebtCapacity'] ?? 0), 2),
            'activeReservedCommission' => round($activeReservedCommission, 2),
            'availableCommissionCapacity' => round((float) ($capacity['availableCommissionCapacity'] ?? 0), 2),
            'maxRefundable' => round($maxRefundable, 2),
            'grossRefundBalance' => round($currentBalance, 2),
            'depositGap' => 0.0,
            'totalRevenue' => round((float) $financial['totalRevenue'], 2),
            'completedJobs' => $this->completedBookingsCount($worker),
            'totalCommission' => round($totalCommission, 2),
            'adminCommissionBalance' => round($adminCommissionBalance, 2),
            'withdrawnAdminRevenueTotal' => round($withdrawnAdminRevenue, 2),
            'commissionDue' => round($indebtednessBalance, 2),
            'totalSettled' => round((float) $debt['totalSettled'], 2),
            'totalRefunded' => round((float) $financial['totalRefunded'], 2),
            'manualDebtDue' => round($adminLoanBalance, 2),
            'adminFeeDue' => round($indebtednessBalance, 2),
            'outstandingAdministrationDue' => round($adminLoanBalance + $indebtednessBalance, 2),
            'utilizationPercent' => round((float) $financial['utilizationPercent'], 1),
            'status' => (string) $financial['status'],
            'isFinancialAccountActive' => (bool) ($worker->deposit?->is_active ?? true),
        ];
    }

    public function suggestedAmounts(Worker $worker, string $type): array
    {
        $snapshot = $this->snapshot($worker);
        $suggestions = [];

        if ($type === 'deposit' && $snapshot['debtBalance'] > 0) {
            $this->addSuggestion($suggestions, (float) $snapshot['debtBalance'], __('cleaning_finance_guidance.suggestions.full_outstanding_due'));
        }

        return $suggestions;
    }

    public function validationMessage(Worker $worker, string $type, float $amount): ?string
    {
        if (! in_array($type, self::TYPES, true)) {
            return __('cleaning_finance_guidance.validation.type_required');
        }

        if ($type === 'refund') {
            $message = $this->fullRefundValidationMessage($worker);
            if ($message !== null) {
                return $message;
            }

            $workerRefund = (float) $this->snapshot($worker)['maxRefundable'];
            if ($amount > 0 && abs($amount - $workerRefund) > 0.009) {
                return app()->isLocale('ar')
                    ? 'يتم احتساب المبلغ المعاد للعامل تلقائياً بعد استرداد الدين الإداري، ولا يمكن إدخال مبلغ جزئي.'
                    : 'The worker refund is calculated automatically after recovering the administration loan; partial amounts are not allowed.';
            }

            return null;
        }

        if ($amount <= 0) {
            return __('cleaning_finance_guidance.validation.amount_positive');
        }

        if ($type === 'debt') {
            return null;
        }

        return null;
    }

    public function projectedBalance(Worker $worker, string $type, float $amount): ?float
    {
        if ($this->validationMessage($worker, $type, $amount) !== null) {
            return null;
        }

        if ($type === 'refund') {
            return 0.0;
        }

        $snapshot = $this->snapshot($worker);
        $depositBalance = (float) $snapshot['depositBalance'];
        $debtBalance = (float) $snapshot['debtBalance'];

        return round(match ($type) {
            'deposit' => $depositBalance + max(0.0, $amount - $debtBalance),
            'debt' => max(0.0, $depositBalance - $amount),
            default => $depositBalance,
        }, 2);
    }

    public function create(Worker $worker, string $type, float $amount, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        if ($type === 'refund') {
            return $this->refundFullBalance($worker, $notes, $createdByAdminId);
        }

        $validationMessage = $this->validationMessage($worker, $type, $amount);
        if ($validationMessage !== null) {
            throw new InvalidArgumentException($validationMessage);
        }

        if ($type === 'debt' && mb_trim((string) $notes) === '') {
            throw new InvalidArgumentException(app()->isLocale('ar') ? 'الملاحظات مطلوبة عند إضافة مديونية.' : 'Notes are required when adding worker debt.');
        }

        return match ($type) {
            'deposit' => $this->depositService->recordDeposit($worker, $amount, 'admin_manual_deposit', $notes, $createdByAdminId),
            'debt' => $this->depositService->recordDebtCharge($worker, $amount, 'admin_manual_debt', $notes, $createdByAdminId),
            default => throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.type_required')),
        };
    }

    public function refundFullBalance(Worker $worker, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $validationMessage = $this->fullRefundValidationMessage($worker);
        if ($validationMessage !== null) {
            throw new InvalidArgumentException($validationMessage);
        }

        return DB::transaction(function () use ($worker, $notes, $createdByAdminId): CleaningDepositTransaction {
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

            $lockedWorker = $worker->fresh(['deposit']) ?? $worker;
            $indebtednessBalance = max(0.0, (float) $account->debt_balance);
            $activeReservedCommission = (float) ($this->solvencyService->workerCapacitySummary($lockedWorker)['activeReservedCommission'] ?? 0);

            if ($indebtednessBalance > 0) {
                throw new InvalidArgumentException(app()->isLocale('ar') ? 'يجب تسوية المديونية كاملة قبل تنفيذ الاسترداد.' : 'The outstanding indebtedness must be settled before the refund.');
            }

            if ($activeReservedCommission > 0) {
                throw new InvalidArgumentException(app()->isLocale('ar') ? 'لا يمكن تنفيذ الاسترداد مع وجود عمولات محجوزة لطلبات نشطة.' : 'The refund cannot be completed while active orders reserve commission.');
            }

            $depositBefore = max(0.0, (float) $account->current_balance);
            $adminLoanBalance = $this->debtService->loanBalance($lockedWorker);
            $loanRecovered = min($depositBefore, $adminLoanBalance);
            $workerRefund = max(0.0, $depositBefore - $loanRecovered);
            $withdrawnAdminRevenueBefore = max(0.0, (float) ($account->admin_revenue_withdrawn_total ?? 0));
            $adminCommissionBalance = max(0.0, $this->commissionTotalFor($worker->id) - $withdrawnAdminRevenueBefore);

            if ($depositBefore <= 0 && $adminCommissionBalance <= 0) {
                throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.no_refundable_balance'));
            }

            $account->current_balance = 0;
            $account->withdrawn_total = (float) $account->withdrawn_total + $workerRefund;
            $account->admin_revenue_withdrawn_total = $withdrawnAdminRevenueBefore + $adminCommissionBalance;
            $account->save();

            $transaction = CleaningDepositTransaction::query()->create([
                'worker_id' => $worker->id,
                'created_by_admin_id' => $createdByAdminId,
                'type' => 'refund',
                'amount' => $workerRefund,
                'debt_settled_amount' => $loanRecovered,
                'admin_revenue_withdrawn_amount' => $adminCommissionBalance,
                'balance_before' => $depositBefore,
                'balance_after' => 0,
                'debt_balance_before' => 0,
                'debt_balance_after' => 0,
                'reference' => 'admin_full_account_refund',
                'notes' => $notes,
            ]);

            $this->depositService->syncEligibilityStatus($worker->fresh(['deposit']) ?? $worker);

            return $transaction;
        });
    }

    public function settleFullDebt(Worker $worker, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $amount = (float) $this->snapshot($worker)['debtBalance'];
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.no_outstanding_due'));
        }

        return $this->debtService->recordSettlement($worker, $amount, 'admin_full_debt_settlement', $notes, $createdByAdminId);
    }

    private function fullRefundValidationMessage(Worker $worker): ?string
    {
        $snapshot = $this->snapshot($worker);

        if ((float) $snapshot['debtBalance'] > 0) {
            return app()->isLocale('ar') ? 'يجب تسوية المديونية كاملة قبل تنفيذ الاسترداد.' : 'The outstanding indebtedness must be settled before the refund.';
        }

        if ((float) $snapshot['activeReservedCommission'] > 0) {
            return app()->isLocale('ar') ? 'لا يمكن تنفيذ الاسترداد مع وجود عمولات محجوزة لطلبات نشطة.' : 'The refund cannot be completed while active orders reserve commission.';
        }

        if ((float) $snapshot['depositBalance'] <= 0 && (float) $snapshot['adminCommissionBalance'] <= 0) {
            return __('cleaning_finance_guidance.validation.no_refundable_balance');
        }

        return null;
    }

    private function commissionTotalFor(int $workerId): float
    {
        $prefix = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%';

        return (float) CleaningDepositTransaction::query()
            ->where('worker_id', $workerId)
            ->where(function (Builder $query) use ($prefix): void {
                $query->whereIn('type', ['commission', 'admin_fee'])
                    ->orWhere(function (Builder $query) use ($prefix): void {
                        $query->where('type', 'debt')->where('reference', 'like', $prefix);
                    });
            })
            ->sum('amount');
    }

    private function completedBookingsCount(Worker $worker): int
    {
        return CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Completed->value)
            ->where(function (Builder $query) use ($worker): void {
                $query->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments->where('worker_id', $worker->id)->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                    });
            })
            ->count();
    }

    private function addSuggestion(array &$suggestions, float $amount, string $label): void
    {
        if ($amount <= 0) {
            return;
        }

        $key = number_format($amount, 2, '.', '');
        $suggestions[$key] = $label.' — '.$this->money($amount);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2).' '.config('app.currency', 'SYP');
    }
}
