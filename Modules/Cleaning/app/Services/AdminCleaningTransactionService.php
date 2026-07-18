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
    public const TYPES = ['deposit', 'debt', 'settlement', 'refund'];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
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

    /**
     * @return array{
     *     currentBalance: float,
     *     depositedTotal: float,
     *     withdrawnTotal: float,
     *     minimumRequired: float,
     *     maxNegativeBalance: float,
     *     maxRefundable: float,
     *     depositGap: float,
     *     totalRevenue: float,
     *     completedJobs: int,
     *     totalCommission: float,
     *     commissionDue: float,
     *     totalSettled: float,
     *     totalRefunded: float,
     *     manualDebtDue: float,
     *     adminFeeDue: float,
     *     outstandingAdministrationDue: float,
     *     utilizationPercent: float,
     *     status: string
     * }
     */
    public function snapshot(Worker $worker): array
    {
        $worker->loadMissing('deposit');

        $financial = $this->depositService->financialSummary($worker);
        $debt = $this->debtService->summary($worker);
        $limits = $this->depositService->resolveLimits($worker);
        $hasDepositAccount = $worker->deposit !== null;
        $currentBalance = (float) ($worker->deposit?->current_balance ?? 0);
        $depositedTotal = (float) ($worker->deposit?->deposited_total ?? 0);
        $withdrawnTotal = (float) ($worker->deposit?->withdrawn_total ?? 0);
        $maxNegativeBalance = max(0.0, (float) $limits['maxNegativeBalance']);
        $maxRefundable = $hasDepositAccount
            ? max(0.0, $currentBalance + $maxNegativeBalance)
            : 0.0;

        return [
            'currentBalance' => round($currentBalance, 2),
            'depositedTotal' => round($depositedTotal, 2),
            'withdrawnTotal' => round($withdrawnTotal, 2),
            'minimumRequired' => round((float) $limits['minimumRequired'], 2),
            'maxNegativeBalance' => round($maxNegativeBalance, 2),
            'maxRefundable' => round($maxRefundable, 2),
            'depositGap' => round(max(0.0, (float) $limits['minimumRequired'] - $currentBalance), 2),
            'totalRevenue' => round((float) $financial['totalRevenue'], 2),
            'completedJobs' => $this->completedBookingsCount($worker),
            'totalCommission' => round((float) $financial['totalCommission'], 2),
            'commissionDue' => round((float) $financial['commissionDue'], 2),
            'totalSettled' => round((float) $debt['totalSettled'], 2),
            'totalRefunded' => round((float) $financial['totalRefunded'], 2),
            'manualDebtDue' => round((float) $debt['manualDebtDue'], 2),
            'adminFeeDue' => round((float) $debt['adminFeeDue'], 2),
            'outstandingAdministrationDue' => round((float) $debt['outstandingAdministrationDue'], 2),
            'utilizationPercent' => round((float) $financial['utilizationPercent'], 1),
            'status' => (string) $financial['status'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function suggestedAmounts(Worker $worker, string $type): array
    {
        $snapshot = $this->snapshot($worker);
        $suggestions = [];

        if ($type === 'deposit') {
            $this->addSuggestion(
                $suggestions,
                $snapshot['depositGap'],
                __('cleaning_finance_guidance.suggestions.deposit_gap'),
            );
            $this->addSuggestion(
                $suggestions,
                $snapshot['minimumRequired'],
                __('cleaning_finance_guidance.suggestions.minimum_required'),
            );
        }

        if ($type === 'settlement') {
            $this->addSuggestion(
                $suggestions,
                $snapshot['manualDebtDue'],
                __('cleaning_finance_guidance.suggestions.manual_debt_due'),
            );
            $this->addSuggestion(
                $suggestions,
                $snapshot['adminFeeDue'],
                __('cleaning_finance_guidance.suggestions.admin_fee_due'),
            );
            $this->addSuggestion(
                $suggestions,
                $snapshot['outstandingAdministrationDue'],
                __('cleaning_finance_guidance.suggestions.full_outstanding_due'),
            );
        }

        if ($type === 'refund') {
            $this->addSuggestion(
                $suggestions,
                max(0.0, $snapshot['currentBalance']),
                __('cleaning_finance_guidance.suggestions.current_positive_balance'),
            );
            $this->addSuggestion(
                $suggestions,
                $snapshot['maxRefundable'],
                __('cleaning_finance_guidance.suggestions.maximum_refundable'),
            );
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

        if ($type === 'settlement') {
            if ($snapshot['outstandingAdministrationDue'] <= 0) {
                return __('cleaning_finance_guidance.validation.no_outstanding_due');
            }

            if ($amount > $snapshot['outstandingAdministrationDue']) {
                return __('cleaning_finance_guidance.validation.settlement_exceeds_due', [
                    'amount' => $this->money($snapshot['outstandingAdministrationDue']),
                ]);
            }
        }

        if ($type === 'refund') {
            if ($snapshot['maxRefundable'] <= 0) {
                return __('cleaning_finance_guidance.validation.no_refundable_balance');
            }

            if ($amount > $snapshot['maxRefundable']) {
                return __('cleaning_finance_guidance.validation.refund_exceeds_available', [
                    'amount' => $this->money($snapshot['maxRefundable']),
                ]);
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
        $currentBalance = $snapshot['currentBalance'];

        return round(match ($type) {
            'deposit', 'debt' => $currentBalance + $amount,
            'refund' => $currentBalance - $amount,
            'settlement' => $currentBalance + ($amount - (2 * min($amount, $snapshot['manualDebtDue']))),
            default => $currentBalance,
        }, 2);
    }

    public function create(
        Worker $worker,
        string $type,
        float $amount,
        ?string $notes,
        ?int $createdByAdminId,
    ): CleaningDepositTransaction {
        $validationMessage = $this->validationMessage($worker, $type, $amount);

        if ($validationMessage !== null) {
            throw new InvalidArgumentException($validationMessage);
        }

        return match ($type) {
            'deposit' => $this->depositService->recordDeposit($worker, $amount, 'admin_manual', $notes, $createdByAdminId),
            'debt' => $this->debtService->recordDebt($worker, $amount, 'admin_manual_debt', $notes, $createdByAdminId),
            'settlement' => $this->debtService->recordSettlement($worker, $amount, 'admin_manual', $notes, $createdByAdminId),
            'refund' => $this->depositService->recordRefund($worker, $amount, 'admin_manual', $notes, $createdByAdminId),
            default => throw new InvalidArgumentException(__('cleaning_finance_guidance.validation.type_required')),
        };
    }

    private function completedBookingsCount(Worker $worker): int
    {
        return CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Completed->value)
            ->where(function (Builder $query) use ($worker): void {
                $query->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                    });
            })
            ->count();
    }

    /**
     * @param array<string, string> $suggestions
     */
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
