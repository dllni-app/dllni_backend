<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningFinancialPenalty;
use App\Models\Worker;
use App\Notifications\Cleaning\CleaningFinancialPenaltyNotification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningCancellationFinancialPenaltyService
{
    private const REFERENCE_PREFIX = 'cleaning_cancellation_penalty:';

    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function apply(
        CleaningBooking $booking,
        float $amount,
        string $notes,
        ?int $appliedByAdminId,
    ): CleaningFinancialPenalty {
        $normalizedNotes = mb_trim($notes);

        if ($amount <= 0) {
            throw new InvalidArgumentException('قيمة الغرامة يجب أن تكون أكبر من صفر.');
        }

        if ($normalizedNotes === '') {
            throw new InvalidArgumentException('ملاحظات الغرامة مطلوبة.');
        }

        $penalty = DB::transaction(function () use ($booking, $amount, $normalizedNotes, $appliedByAdminId): CleaningFinancialPenalty {
            $lockedBooking = CleaningBooking::query()
                ->with(['workerAssignments', 'financialPenalty'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $lockedBooking->status instanceof CleaningBookingStatus
                ? $lockedBooking->status
                : CleaningBookingStatus::tryFrom((string) $lockedBooking->status);

            if ($status !== CleaningBookingStatus::Cancelled) {
                throw new InvalidArgumentException('يمكن فرض الغرامة على الطلبات الملغاة فقط.');
            }

            if ((string) $lockedBooking->cancelled_by_role !== 'worker' || $lockedBooking->cancelled_by_worker_id === null) {
                throw new InvalidArgumentException('لا يمكن فرض الغرامة لأن العامل الذي ألغى الطلب غير معروف.');
            }

            if ($lockedBooking->financialPenalty instanceof CleaningFinancialPenalty) {
                throw new InvalidArgumentException('تمت إضافة غرامة مالية لهذا الطلب مسبقاً.');
            }

            if ($amount > (float) $lockedBooking->total_price) {
                throw new InvalidArgumentException('لا يمكن أن تتجاوز الغرامة إجمالي قيمة الطلب.');
            }

            $worker = Worker::query()
                ->with(['user', 'deposit'])
                ->whereKey($lockedBooking->cancelled_by_worker_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->workerBelongsToBooking($lockedBooking, $worker)) {
                throw new InvalidArgumentException('العامل الذي ألغى الطلب غير مرتبط بهذا الطلب.');
            }

            $reference = self::REFERENCE_PREFIX.$lockedBooking->id;
            $transaction = CleaningDepositTransaction::query()
                ->where('worker_id', $worker->id)
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($transaction instanceof CleaningDepositTransaction) {
                if (abs((float) $transaction->amount - $amount) > 0.009) {
                    throw new InvalidArgumentException('يوجد قيد مالي سابق مختلف لهذا الطلب.');
                }

                $financialSource = (float) $transaction->debt_balance_after > (float) $transaction->debt_balance_before
                    ? CleaningFinancialPenalty::SOURCE_DEBT
                    : CleaningFinancialPenalty::SOURCE_DEPOSIT;
            } else {
                $result = $this->depositService->recordFinancialPenalty(
                    worker: $worker,
                    amount: $amount,
                    reference: $reference,
                    notes: $normalizedNotes,
                    createdByAdminId: $appliedByAdminId,
                );
                $transaction = $result['transaction'];
                $financialSource = $result['financialSource'];
            }

            return CleaningFinancialPenalty::query()->create([
                'cleaning_booking_id' => $lockedBooking->id,
                'worker_id' => $worker->id,
                'financial_transaction_id' => $transaction->id,
                'financial_source' => $financialSource,
                'amount' => $amount,
                'status' => CleaningFinancialPenalty::STATUS_ACTIVE,
                'notes' => $normalizedNotes,
                'cancellation_reason_snapshot' => $lockedBooking->cancellation_reason,
                'cancellation_offset_minutes' => $lockedBooking->cancellation_offset_minutes,
                'applied_by_admin_id' => $appliedByAdminId,
                'applied_at' => now(),
            ])->load(['booking', 'worker.user', 'financialTransaction', 'appliedByAdmin']);
        });

        $recipient = $penalty->worker?->user;
        if ($recipient !== null) {
            $recipient->notify(new CleaningFinancialPenaltyNotification($penalty));
        }

        return $penalty;
    }

    public function predictedSource(CleaningBooking $booking, float $amount): ?string
    {
        if ($amount <= 0 || $booking->cancelled_by_worker_id === null) {
            return null;
        }

        $worker = Worker::query()->with('deposit')->find($booking->cancelled_by_worker_id);
        if (! $worker instanceof Worker) {
            return null;
        }

        return (float) ($worker->deposit?->current_balance ?? 0) >= $amount
            ? CleaningFinancialPenalty::SOURCE_DEPOSIT
            : CleaningFinancialPenalty::SOURCE_DEBT;
    }

    private function workerBelongsToBooking(CleaningBooking $booking, Worker $worker): bool
    {
        if ((int) $booking->worker_id === (int) $worker->id) {
            return true;
        }

        return $booking->workerAssignments->contains(
            fn ($assignment): bool => (int) $assignment->worker_id === (int) $worker->id,
        );
    }
}
