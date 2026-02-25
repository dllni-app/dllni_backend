<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingService
{
    public function store(CleaningBookingData $data): CleaningBooking
    {
        return DB::transaction(static function () use ($data) {
            $booking = CleaningBooking::create($data->onlyModelAttributes());

            return $booking;
        });
    }

    public function update(CleaningBookingData $data, CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($data, $booking) {
            tap($booking)->update($data->onlyModelAttributes());

            return $booking;
        });
    }

    public function accept(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($booking) {
            $allowedStatuses = [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::Confirmed,
                CleaningBookingStatus::WorkerAssigned,
            ];

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Booking cannot be accepted in current status.');
            }

            $workerId = auth()->user()?->worker?->id;
            if ($booking->worker_id !== null && $booking->worker_id !== $workerId) {
                throw new InvalidArgumentException('Booking is assigned to another worker.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::WorkerAssigned,
                'worker_id' => $booking->worker_id ?? $workerId,
            ]);

            return $booking->fresh();
        });
    }

    public function reject(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        return DB::transaction(static function () use ($booking, $reason) {
            $allowedStatuses = [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::Confirmed,
                CleaningBookingStatus::WorkerAssigned,
            ];

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Booking cannot be rejected in current status.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason ?? 'Rejected by worker',
            ]);

            return $booking->fresh();
        });
    }

    public function startTravel(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($booking) {
            $allowedStatuses = [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::WorkerArrived,
            ];

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Booking cannot start travel in current status.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::WorkerOnTheWay,
            ]);

            return $booking->fresh();
        });
    }

    public function complete(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::InProgress) {
                throw new InvalidArgumentException('Booking must be in progress to complete.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::Completed,
                'work_finished_at' => now(),
            ]);

            return $booking->fresh();
        });
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        return DB::transaction(static function () use ($booking, $reason) {
            $allowedStatuses = [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::WorkerOnTheWay,
                CleaningBookingStatus::WorkerArrived,
                CleaningBookingStatus::InProgress,
            ];

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Booking cannot be cancelled in current status.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $booking->fresh();
        });
    }
}
