<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Events\CleaningBookingPriceAdjustmentRequested;
use Modules\Cleaning\Events\CleaningBookingPriceAdjustmentResolved;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;

final class CleaningBookingPriceAdjustmentService
{
    /**
     * @param  float|int|string  $proposedTotalPrice
     */
    public function requestFromWorker(
        CleaningBooking $booking,
        Worker $worker,
        float|int|string $proposedTotalPrice,
        ?string $reason = null,
    ): CleaningBookingPriceAdjustmentRequest {
        $request = DB::transaction(function () use ($booking, $worker, $proposedTotalPrice, $reason): CleaningBookingPriceAdjustmentRequest {
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureWorkerCanRequest($booking, $worker);
            $this->ensureBookingCanReceiveRequest($booking);
            $this->ensureNoPendingRequest($booking);

            $adjustment = CleaningBookingPriceAdjustmentRequest::query()->create([
                'cleaning_booking_id' => $booking->id,
                'worker_id' => $worker->id,
                'old_total_price' => $booking->total_price ?? 0,
                'proposed_total_price' => $proposedTotalPrice,
                'reason' => $reason,
                'status' => CleaningPriceAdjustmentRequestStatus::Pending->value,
            ]);

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => AlertType::PriceAdjustmentRequested->value,
                'severity' => AlertSeverity::High->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'cleaning_worker_price_adjustment',
                    'request_id' => $adjustment->id,
                    'booking_id' => $booking->id,
                    'worker_id' => $worker->id,
                    'old_total_price' => (float) ($booking->total_price ?? 0),
                    'proposed_total_price' => (float) $proposedTotalPrice,
                    'reason' => $reason,
                ],
            ]);

            return $adjustment->fresh(['booking', 'worker.user']);
        });

        BroadcastAfterResponse::send(new CleaningBookingPriceAdjustmentRequested(
            cleaningBookingId: (int) $request->cleaning_booking_id,
            requestId: (int) $request->id,
            workerId: (int) $request->worker_id,
            oldTotalPrice: (float) $request->old_total_price,
            proposedTotalPrice: (float) $request->proposed_total_price,
            status: CleaningPriceAdjustmentRequestStatus::Pending->value,
        ));

        return $request;
    }

    /**
     * @param  float|int|string  $adminFinalTotalPrice
     */
    public function approve(
        CleaningBookingPriceAdjustmentRequest $request,
        float|int|string $adminFinalTotalPrice,
        ?string $adminNote,
        User $admin,
    ): CleaningBooking {
        $booking = DB::transaction(function () use ($request, $adminFinalTotalPrice, $adminNote, $admin): CleaningBooking {
            $request = CleaningBookingPriceAdjustmentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePending($request);

            $booking = CleaningBooking::query()
                ->whereKey($request->cleaning_booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentTotal = (float) ($booking->total_price ?? 0);
            $newTotal = (float) $adminFinalTotalPrice;
            $delta = $newTotal - $currentTotal;

            $booking->forceFill([
                'base_price' => max(0, (float) ($booking->base_price ?? 0) + $delta),
                'total_price' => $newTotal,
                'is_pricing_final' => true,
            ])->save();

            $request->forceFill([
                'status' => CleaningPriceAdjustmentRequestStatus::Approved->value,
                'admin_final_total_price' => $newTotal,
                'admin_note' => $adminNote,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $booking->fresh(['workerAssignments.worker.user', 'rooms.assignedWorker.user']);
        });

        BroadcastAfterResponse::send(new CleaningBookingPriceAdjustmentResolved(
            cleaningBookingId: (int) $booking->id,
            requestId: (int) $request->id,
            requestStatus: CleaningPriceAdjustmentRequestStatus::Approved->value,
            totalPrice: (float) $booking->total_price,
            canStartWork: true,
        ));

        return $booking;
    }

    public function reject(
        CleaningBookingPriceAdjustmentRequest $request,
        ?string $adminNote,
        User $admin,
    ): CleaningBookingPriceAdjustmentRequest {
        return $this->resolve($request, CleaningPriceAdjustmentRequestStatus::Rejected, $adminNote, $admin);
    }

    public function resolveWithoutChange(
        CleaningBookingPriceAdjustmentRequest $request,
        ?string $adminNote,
        User $admin,
    ): CleaningBookingPriceAdjustmentRequest {
        return $this->resolve($request, CleaningPriceAdjustmentRequestStatus::ResolvedWithoutChange, $adminNote, $admin);
    }

    public function hasPendingRequest(CleaningBooking $booking): bool
    {
        return CleaningBookingPriceAdjustmentRequest::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', CleaningPriceAdjustmentRequestStatus::Pending->value)
            ->exists();
    }

    public function assertNoPendingRequestBeforeStart(CleaningBooking $booking): void
    {
        if ($this->hasPendingRequest($booking)) {
            throw new InvalidArgumentException('يوجد طلب تعديل سعر قيد مراجعة الإدارة. لا يمكن بدء العمل حالياً.');
        }
    }

    private function resolve(
        CleaningBookingPriceAdjustmentRequest $request,
        CleaningPriceAdjustmentRequestStatus $status,
        ?string $adminNote,
        User $admin,
    ): CleaningBookingPriceAdjustmentRequest {
        $resolved = DB::transaction(function () use ($request, $status, $adminNote, $admin): CleaningBookingPriceAdjustmentRequest {
            $request = CleaningBookingPriceAdjustmentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePending($request);

            $request->forceFill([
                'status' => $status->value,
                'admin_note' => $adminNote,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $request->fresh(['booking', 'worker.user']);
        });

        BroadcastAfterResponse::send(new CleaningBookingPriceAdjustmentResolved(
            cleaningBookingId: (int) $resolved->cleaning_booking_id,
            requestId: (int) $resolved->id,
            requestStatus: $status->value,
            totalPrice: (float) ($resolved->booking?->total_price ?? 0),
            canStartWork: true,
        ));

        return $resolved;
    }

    private function ensureWorkerCanRequest(CleaningBooking $booking, Worker $worker): void
    {
        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->exists();

        if ((int) $booking->worker_id !== (int) $worker->id && ! $hasWorkerAssignment) {
            throw new InvalidArgumentException('لا يمكنك طلب تعديل سعر لهذا الطلب.');
        }
    }

    private function ensureBookingCanReceiveRequest(CleaningBooking $booking): void
    {
        if (! in_array($booking->status, [
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        ], true)) {
            throw new InvalidArgumentException('لا يمكن طلب تعديل السعر في حالة الطلب الحالية.');
        }
    }

    private function ensureNoPendingRequest(CleaningBooking $booking): void
    {
        if ($this->hasPendingRequest($booking)) {
            throw new InvalidArgumentException('يوجد طلب تعديل سعر قيد المراجعة لهذا الطلب.');
        }
    }

    private function ensurePending(CleaningBookingPriceAdjustmentRequest $request): void
    {
        $status = $request->status instanceof CleaningPriceAdjustmentRequestStatus
            ? $request->status
            : CleaningPriceAdjustmentRequestStatus::tryFrom((string) $request->status);

        if ($status !== CleaningPriceAdjustmentRequestStatus::Pending) {
            throw new InvalidArgumentException('تمت معالجة طلب تعديل السعر مسبقاً.');
        }
    }
}
