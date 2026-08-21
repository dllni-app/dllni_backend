<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningFinancialPenalty;
use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\CleaningFinancialPenaltyNotification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningCancellationFinancialPenaltyService
{
    private const REFERENCE_PREFIX = 'cleaning_cancellation_penalty:';
    private const REVERSAL_REFERENCE_PREFIX = 'cleaning_cancellation_penalty_reversal:';

    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    /**
     * Automatically records the configured cancellation penalty for the party that
     * cancelled the booking. One booking can only have one cancellation penalty.
     */
    public function recordAutomatic(CleaningBooking $booking): ?CleaningFinancialPenalty
    {
        $amount = CleaningFinancialSetting::currentUserCancellationFee();

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($booking, $amount): ?CleaningFinancialPenalty {
            $lockedBooking = CleaningBooking::query()
                ->with(['customer', 'worker.user', 'workerAssignments.worker.user'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== CleaningBookingStatus::Cancelled) {
                return null;
            }

            $existing = CleaningFinancialPenalty::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->first();

            if ($existing instanceof CleaningFinancialPenalty) {
                return $existing;
            }

            $role = mb_strtolower(mb_trim((string) $lockedBooking->cancelled_by_role));

            if ($role === CleaningFinancialPenalty::ROLE_WORKER) {
                return $this->createWorkerPenalty(
                    booking: $lockedBooking,
                    amount: $amount,
                    notes: 'غرامة إلغاء تلقائية على العامل الذي ألغى الطلب.',
                    appliedByAdminId: null,
                );
            }

            if ($role === CleaningFinancialPenalty::ROLE_CUSTOMER) {
                return $this->createCustomerPenalty($lockedBooking, $amount);
            }

            // Admin/system cancellations do not penalize either party automatically.
            return null;
        });
    }

    /**
     * Backward-compatible manual application for legacy cancelled worker bookings.
     */
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

            if ((string) $lockedBooking->cancelled_by_role !== CleaningFinancialPenalty::ROLE_WORKER) {
                throw new InvalidArgumentException('الغرامة اليدوية متاحة فقط عندما يكون العامل هو من ألغى الطلب.');
            }

            if (CleaningFinancialPenalty::query()->where('cleaning_booking_id', $lockedBooking->id)->exists()) {
                throw new InvalidArgumentException('تمت إضافة غرامة لهذا الطلب مسبقاً.');
            }

            if ($amount > (float) $lockedBooking->total_price) {
                throw new InvalidArgumentException('قيمة الغرامة لا يمكن أن تتجاوز إجمالي قيمة الطلب.');
            }

            return $this->createWorkerPenalty($lockedBooking, $amount, $normalizedNotes, $appliedByAdminId);
        });
    }

    public function markReviewed(CleaningFinancialPenalty $penalty, ?int $adminId): CleaningFinancialPenalty
    {
        if ($penalty->isCancelled()) {
            throw new InvalidArgumentException('لا يمكن مراجعة غرامة ملغاة.');
        }

        $penalty->forceFill([
            'review_status' => CleaningFinancialPenalty::REVIEW_REVIEWED,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ])->save();

        return $penalty->fresh(['reviewedByAdmin']) ?? $penalty;
    }

    public function markNeedsReview(CleaningFinancialPenalty $penalty): CleaningFinancialPenalty
    {
        if ($penalty->isCancelled()) {
            throw new InvalidArgumentException('لا يمكن إعادة غرامة ملغاة للمراجعة.');
        }

        $penalty->forceFill([
            'review_status' => CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW,
            'reviewed_by_admin_id' => null,
            'reviewed_at' => null,
        ])->save();

        return $penalty->fresh() ?? $penalty;
    }

    public function cancelPenalty(
        CleaningFinancialPenalty $penalty,
        ?int $adminId,
        ?string $reason = null,
    ): CleaningFinancialPenalty {
        return DB::transaction(function () use ($penalty, $adminId, $reason): CleaningFinancialPenalty {
            $lockedPenalty = CleaningFinancialPenalty::query()
                ->with(['booking', 'worker.user', 'customer'])
                ->whereKey($penalty->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPenalty->isCancelled()) {
                return $lockedPenalty;
            }

            if ($lockedPenalty->penalized_role === CleaningFinancialPenalty::ROLE_WORKER) {
                $this->reverseWorkerPenalty($lockedPenalty, $adminId, $reason);
            } elseif ($lockedPenalty->penalized_role === CleaningFinancialPenalty::ROLE_CUSTOMER) {
                $booking = $lockedPenalty->booking;
                if ($booking instanceof CleaningBooking) {
                    $booking->forceFill(['cancellation_fee' => 0])->saveQuietly();
                }
            }

            $lockedPenalty->forceFill([
                'status' => CleaningFinancialPenalty::STATUS_CANCELLED,
                'cancelled_by_admin_id' => $adminId,
                'penalty_cancelled_at' => now(),
                'penalty_cancellation_note' => is_string($reason) && mb_trim($reason) !== '' ? mb_trim($reason) : null,
            ])->save();

            return $lockedPenalty->fresh([
                'booking',
                'worker.user',
                'customer',
                'reviewedByAdmin',
                'cancelledByAdmin',
            ]) ?? $lockedPenalty;
        });
    }

    private function createCustomerPenalty(CleaningBooking $booking, float $amount): CleaningFinancialPenalty
    {
        $booking->forceFill(['cancellation_fee' => $amount])->saveQuietly();

        $penalty = CleaningFinancialPenalty::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => null,
            'customer_id' => $booking->customer_id,
            'penalized_role' => CleaningFinancialPenalty::ROLE_CUSTOMER,
            'financial_transaction_id' => null,
            'financial_source' => CleaningFinancialPenalty::SOURCE_CUSTOMER_FEE,
            'amount' => $amount,
            'status' => CleaningFinancialPenalty::STATUS_ACTIVE,
            'review_status' => CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW,
            'notes' => 'غرامة إلغاء تلقائية على المستخدم الذي ألغى الطلب.',
            'cancellation_reason_snapshot' => $booking->cancellation_reason,
            'cancellation_offset_minutes' => $booking->cancellation_offset_minutes,
            'applied_by_admin_id' => null,
            'applied_at' => now(),
        ]);

        $customer = $booking->customer;
        DB::afterCommit(static function () use ($customer, $penalty): void {
            if ($customer instanceof User) {
                $customer->notify(new CleaningFinancialPenaltyNotification($penalty));
            }
        });

        return $penalty->fresh(['booking', 'customer']) ?? $penalty;
    }

    private function createWorkerPenalty(
        CleaningBooking $booking,
        float $amount,
        string $notes,
        ?int $appliedByAdminId,
    ): CleaningFinancialPenalty {
        $worker = $this->resolveCancellingWorker($booking);
        $worker->loadMissing(['deposit', 'user']);

        $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $financialSource = $depositBalance <= 0
            ? CleaningFinancialPenalty::SOURCE_DEBT
            : ($depositBalance >= $amount ? CleaningFinancialPenalty::SOURCE_DEPOSIT : CleaningFinancialPenalty::SOURCE_MIXED);

        $booking->forceFill(['cancellation_fee' => $amount])->saveQuietly();

        $penalty = CleaningFinancialPenalty::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'customer_id' => null,
            'penalized_role' => CleaningFinancialPenalty::ROLE_WORKER,
            'financial_source' => $financialSource,
            'amount' => $amount,
            'status' => CleaningFinancialPenalty::STATUS_ACTIVE,
            'review_status' => CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW,
            'notes' => $notes,
            'cancellation_reason_snapshot' => $booking->cancellation_reason,
            'cancellation_offset_minutes' => $booking->cancellation_offset_minutes,
            'applied_by_admin_id' => $appliedByAdminId,
            'applied_at' => now(),
        ]);

        $transaction = $this->depositService->recordDebtCharge(
            worker: $worker,
            amount: $amount,
            reference: self::REFERENCE_PREFIX.$penalty->id,
            notes: 'غرامة إلغاء الطلب '.$booking->booking_number.' — '.$notes,
            createdByAdminId: $appliedByAdminId,
        );

        $penalty->forceFill(['financial_transaction_id' => $transaction->id])->save();

        DB::afterCommit(static function () use ($worker, $penalty): void {
            $worker->user?->notify(new CleaningFinancialPenaltyNotification($penalty));
        });

        return $penalty->fresh(['booking', 'worker.user', 'financialTransaction', 'appliedByAdmin']) ?? $penalty;
    }

    private function reverseWorkerPenalty(CleaningFinancialPenalty $penalty, ?int $adminId, ?string $reason): void
    {
        $worker = $penalty->worker;
        if (! $worker instanceof Worker || $penalty->financial_transaction_id === null) {
            return;
        }

        $reference = self::REVERSAL_REFERENCE_PREFIX.$penalty->id;
        if (CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->where('reference', $reference)
            ->exists()) {
            return;
        }

        $note = 'عكس غرامة إلغاء الطلب '.($penalty->booking?->booking_number ?? $penalty->cleaning_booking_id);
        if (is_string($reason) && mb_trim($reason) !== '') {
            $note .= ' — '.mb_trim($reason);
        }

        // A positive adjustment restores the worker's net financial position. If debt
        // is outstanding it is settled first; any remainder returns to deposit balance.
        $this->depositService->recordAdjustment(
            worker: $worker,
            signedAmount: (float) $penalty->amount,
            reference: $reference,
            notes: $note,
            createdByAdminId: $adminId,
        );
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
