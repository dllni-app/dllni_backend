<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DisputeResolution;
use App\Models\CleaningDepositTransaction;
use App\Models\Dispute;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;

final class DisputeFinancialPenaltyService
{
    private const REFERENCE_PREFIX = 'dispute_worker_penalty:';

    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function apply(
        Dispute $dispute,
        Worker $worker,
        float $amount,
        ?string $notes,
        ?int $appliedByAdminId,
        bool $keepWorkerEarningsFrozen = false,
    ): Dispute {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('dispute_finance.validation.amount_positive'));
        }

        return DB::transaction(function () use ($dispute, $worker, $amount, $notes, $appliedByAdminId, $keepWorkerEarningsFrozen): Dispute {
            $lockedDispute = Dispute::query()
                ->with('booking')
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDispute->financial_penalty_transaction_id !== null) {
                throw new InvalidArgumentException(__('dispute_finance.validation.already_applied'));
            }

            if (! $this->workerBelongsToDisputeBooking($lockedDispute, $worker)) {
                throw new InvalidArgumentException(__('dispute_finance.validation.invalid_worker'));
            }

            $reference = self::REFERENCE_PREFIX.$lockedDispute->id;
            $transaction = CleaningDepositTransaction::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $transaction instanceof CleaningDepositTransaction) {
                $transaction = $this->depositService->recordDebtCharge(
                    worker: $worker,
                    amount: $amount,
                    reference: $reference,
                    notes: $this->composeNotes($lockedDispute, $notes),
                    createdByAdminId: $appliedByAdminId,
                );
            }

            $lockedDispute->forceFill([
                'resolution' => DisputeResolution::WorkerPenalty,
                'worker_earnings_frozen' => $keepWorkerEarningsFrozen,
                'financial_penalty_worker_id' => $worker->id,
                'financial_penalty_amount' => $amount,
                'financial_penalty_notes' => $this->normalizeNotes($notes),
                'financial_penalty_transaction_id' => $transaction->id,
                'financial_penalty_applied_by' => $appliedByAdminId,
                'financial_penalty_applied_at' => now(),
            ])->save();

            return $lockedDispute->fresh([
                'booking',
                'financialPenaltyWorker.user',
                'financialPenaltyTransaction',
                'financialPenaltyAppliedBy',
            ]) ?? $lockedDispute;
        });
    }

    private function workerBelongsToDisputeBooking(Dispute $dispute, Worker $worker): bool
    {
        $booking = $dispute->booking;

        if (! $booking instanceof CleaningBooking) {
            return false;
        }

        if ((int) $booking->worker_id === (int) $worker->id) {
            return true;
        }

        return $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->exists();
    }

    private function composeNotes(Dispute $dispute, ?string $notes): string
    {
        $prefix = __('dispute_finance.transaction_note', [
            'ticket' => $dispute->ticket_number,
        ]);
        $normalizedNotes = $this->normalizeNotes($notes);

        return $normalizedNotes === null ? $prefix : $prefix.' — '.$normalizedNotes;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $normalized = is_string($notes) ? mb_trim($notes) : '';

        return $normalized === '' ? null : $normalized;
    }
}
