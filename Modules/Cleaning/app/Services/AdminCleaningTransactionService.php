<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
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
        $debtBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));
        $activeReservedCommission = (float) ($capacity['activeReservedCommission'] ?? 0);
        $maxRefundable = $debtBalance <= 0 && $activeReservedCommission <= 0 ? $currentBalance : 0.0;

        return [
            'currentBalance' => round($currentBalance, 2),
            'depositBalance' => round($currentBalance, 2),
            'debtBalance' => round($debtBalance, 2),
            'depositedTotal' => round((float) ($worker->deposit?->deposited_total ?? 0), 2),
            'withdrawnTotal' => round((float) ($worker->deposit?->withdrawn_total ?? 0), 2),
            'minimumRequired' => 0.0,
            'maxNegativeBalance' => round((float) $limits['maxNegativeBalance'], 2),
            'allowedDebtLimit' => round((float) $limits['maxNegativeBalance'], 2),
            'remainingDebtCapacity' => round((float) ($capacity['remainingDebtCapacity'] ?? 0), 2),
            'activeReservedCommission' => round($activeReservedCommission, 2),
            'availableCommissionCapacity' => round((float) ($capacity['availableCommissionCapacity'] ?? 0), 2),
            'maxRefundable' => round($maxRefundable, 2),
            'depositGap' => 0.0,
            'totalRevenue' => round((float) $financial['totalRevenue'], 2),
            'completedJobs' => $this->completedBookingsCount($worker),
            'totalCommission' => round((float) $financial['totalCommission'], 2),
            'commissionDue' => round($debtBalance, 2),
            'totalSettled' => round((float) $debt['totalSettled'], 2),
            'totalRefunded' => round((float) $financial['totalRefunded'], 2),
            'manualDebtDue' => round((float) $debt['manualDebtDue'], 2),
            'adminFeeDue' => round((float) $debt['adminFeeDue'], 2),
            'outstandingAdministrationDue' => round($debtBalance, 2),
            'utilizationPercent' => round((float) $financial['utilizationPercent'], 1),
            'status' => (string) $financial['status'],
        ];
    }

    public function suggestedAmounts(Worker $worker, string $type): array
    {
        $snapshot = $this->snapshot($worker);
        $suggestions = [];

        if ($type === 'deposit' && $snapshot['outstandingAdministrationDue'] > 0) {
            $this->addSuggestion($suggestions, (float) $snapshot['outstandingAdministrationDue'], __('cleaning_finance_guidance.suggestions.full_outstanding_due'));
        }

        if ($type === 'refund') {
            $this->addSuggestion($suggestions, (float) $snapshot['maxRefundable'], __('cleaning_finance_guidance.suggestions.maximum_refundable'));
        }

        return $suggestions;
    }

    public function validationMessage(Worker $worker, string $type, float $amount): ?string
    {
        if (! in_array($type, self::TYPES, true)) {
            return __('cleaning_finance_guidance.validation.type_required');
        }
        if ($amount <= 0) {
            return __('cleaning_finance_guidance.validation.amount_positive');
        }

        $snapshot = $this->snapshot($worker);
        if ($type === 'refund') {
            if ((float) $snapshot['outstandingAdministrationDue'] > 0) {
                return app()->isLocale('ar') ? 'يجب تسوية المديونية كاملة قبل استرداد مبلغ الإيداع.' : 'The outstanding debt must be settled before refunding the deposit.';
            }
            if ((float) $snapshot['activeReservedCommission'] > 0) {
                return app()->isLocale('ar') ? 'لا يمكن الاسترداد مع وجود عمولات محجوزة لطلبات نشطة.' : 'The deposit cannot be refunded while active orders reserve platform commission.';
            }
            if ((float) $snapshot['maxRefundable'] <= 0) {
                return __('cleaning_finance_guidance.validation.no_refundable_balance');
            }
            if ($amount > (float) $snapshot['maxRefundable']) {
                return __('cleaning_finance_guidance.validation.refund_exceeds_available', ['amount' => $this->money((float) $snapshot['maxRefundable'])]);
            }
        }

        return null;
    }

    public function projectedBalance(Worker $worker, string $type, float $amount): ?float
    {
        if ($this->validationMessage($worker, $type, $amount) !== null) {
            return null;
        }

        $snapshot = $this->snapshot($worker);
        $depositBalance = (float) $snapshot['depositBalance'];
        $debtBalance = (float) $snapshot['debtBalance'];

        return round(match ($type) {
            'deposit' => $depositBalance + max(0.0, $amount - $debtBalance),
            'debt' => max(0.0, $depositBalance - $amount),
            'refund' => $depositBalance - $amount,
            default => $depositBalance,
        }, 2);
    }

    public function create(Worker $worker, string $type, float $amount, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $validationMessage = $this->validationMessage($worker, $type, $amount);
        if ($validationMessage !== null) {
            throw new InvalidArgumentException($validationMessage);
        }

        if ($type === 'debt' && mb_trim((string) $notes) === '') {
            throw new InvalidArgumentException(app()->isLocale('ar') ? 'الملاحظات مطلوبة عند إضافة مديونية يدوية.' : 'Notes are required when adding manual debt.');
        }

        return match ($type) {
            'deposit' => $this->depositService->recordDeposit($worker, $amount, 'admin_manual_deposit', $notes, $createdByAdminId),
            'debt' => $this->debtService->recordDebt($worker, $amount, 'admin_manual_debt', $notes, $createdByAdminId),
            'refund' => $this->depositService->recordRefund($worker, $amount, 'admin_manual_refund', $notes, $createdByAdminId),
            default => throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.type_required')),
        };
    }

    public function settleFullDebt(Worker $worker, ?string $notes, ?int $createdByAdminId): CleaningDepositTransaction
    {
        $amount = (float) $this->snapshot($worker)['outstandingAdministrationDue'];
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.no_outstanding_due'));
        }

        return $this->debtService->recordSettlement($worker, $amount, 'admin_full_debt_settlement', $notes, $createdByAdminId);
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
