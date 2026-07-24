<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

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

        return DB::transaction(function () use ($booking, $amount, $normalizedNotes, $appliedByAdminId): CleaningFinancialPenalty {
            $lockedBooking = CleaningBooking::query()
                ->with(['worker.user', 'workerAssignments.worker.user'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== CleaningBookingStatus::Cancelled) {
                throw new InvalidArgumentException('يمكن فرض الغرامة على طلب ملغي فقط.');
            }

            if ((string) $lockedBooking->cancelled_by_role !== 'worker') {
                throw new InvalidArgumentException('الغرامة متاحة فقط عندما يكون العامل هو من ألغى الطلب.');
            }

            if (CleaningFinancialPenalty::query()->where('cleaning_booking_id', $lockedBooking->id)->exists()) {
                throw new InvalidArgumentException('تمت إضافة غرامة لهذا الطلب مسبقاً.');
            }

            if ($amount > (float) $lockedBooking->total_price) {
                throw new InvalidArgumentException('قيمة الغرامة لا يمكن أن تتجاوز إجمالي قيمة الطلب.');
            }

            $worker = $this->resolveCancellingWorker($lockedBooking);
            $worker->loadMissing(['deposit', 'user']);

            $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
            $debtBalance = max(0.0, (float) ($worker->deposit?->debt_balance ?? 0));

            if ($depositBalance > 0 && $depositBalance < $amount) {
                throw new InvalidArgumentException('لا يمكن توزيع الغرامة بين رصيد الإيداع والدين.');
            }

            $financialSource = $depositBalance >= $amount
                ? CleaningFinancialPenalty::SOURCE_DEPOSIT
                : CleaningFinancialPenalty::SOURCE_DEBT;

            if ($financialSource === CleaningFinancialPenalty::SOURCE_DEBT && $depositBalance > 0) {
                throw new InvalidArgumentException('يجب أن تكون الغرامة من مصدر مالي واحد فقط.');
            }

            $penalty = CleaningFinancialPenalty::query()->create([
                'cleaning_booking_id' => $lockedBooking->id,
                'worker_id' => $worker->id,
                'financial_source' => $financialSource,
                'amount' => $amount,
                'status' => CleaningFinancialPenalty::STATUS_ACTIVE,
                'notes' => $normalizedNotes,
                'cancellation_reason_snapshot' => $lockedBooking->cancellation_reason,
                'cancellation_offset_minutes' => $lockedBooking->cancellation_offset_minutes,
                'applied_by_admin_id' => $appliedByAdminId,
                'applied_at' => now(),
            ]);

            $transaction = $this->depositService->recordDebtCharge(
                worker: $worker,
                amount: $amount,
                reference: self::REFERENCE_PREFIX.$penalty->id,
                notes: 'غرامة إلغاء الطلب '.$lockedBooking->booking_number.' — '.$normalizedNotes,
                createdByAdminId: $appliedByAdminId,
            );

            $penalty->forceFill(['financial_transaction_id' => $transaction->id])->save();

            DB::afterCommit(static function () use ($worker, $penalty): void {
                $worker->user?->notify(new CleaningFinancialPenaltyNotification($penalty));
            });

            return $penalty->fresh(['booking', 'worker.user', 'financialTransaction', 'appliedByAdmin']) ?? $penalty;
        });
    }

    private function resolveCancellingWorker(CleaningBooking $booking): Worker
    {
        $workerId = (int) ($booking->cancelled_by_worker_id ?? 0);

        if ($workerId <= 0) {
            $workerId = (int) ($booking->worker_id ?? 0);
        }

        if ($workerId <= 0) {
            $workerId = (int) ($booking->workerAssignments
                ->firstWhere('cancelled_by_this_worker', true)?->worker_id ?? 0);
        }

        $worker = $workerId > 0 ? Worker::query()->whereKey($workerId)->lockForUpdate()->first() : null;

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException('تعذر تحديد العامل الذي ألغى الطلب.');
        }

        $belongsToBooking = (int) $booking->worker_id === (int) $worker->id
            || $booking->workerAssignments->contains(fn ($assignment): bool => (int) $assignment->worker_id === (int) $worker->id);

        if (! $belongsToBooking) {
            throw new InvalidArgumentException('العامل المحدد غير مرتبط بهذا الطلب.');
        }

        return $worker;
    }
}
