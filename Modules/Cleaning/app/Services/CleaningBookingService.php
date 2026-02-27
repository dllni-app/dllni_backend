<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\WorkerArrived;
use Modules\Cleaning\Events\WorkerLocationUpdated;
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
            if ($booking->status !== CleaningBookingStatus::Pending) {
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
            if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking cannot start travel in current status.');
            }

            $booking->update(['started_travel_at' => now()]);

            return $booking->fresh();
        });
    }

    public function updateLocation(CleaningBooking $booking, float $latitude, float $longitude): void
    {
        if ($booking->status !== CleaningBookingStatus::WorkerAssigned || $booking->started_travel_at === null) {
            throw new InvalidArgumentException('Worker must have started travel to send location updates.');
        }

        $worker = auth()->user()?->worker;
        if (! $worker || $booking->worker_id !== $worker->id) {
            throw new InvalidArgumentException('Only the assigned worker can update location.');
        }

        WorkerLocationUpdated::dispatch($booking->id, $latitude, $longitude, $worker->id);
    }

    public function arrive(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking must be in worker_assigned status to mark arrival.');
            }
            if ($booking->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
            }

            $booking->update(['arrived_at' => now()]);
            $booking->refresh();

            WorkerArrived::dispatch($booking->id, $booking->arrived_at->toIso8601String());

            return $booking->fresh();
        });
    }

    public function startWork(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(static function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking must be assigned to start work.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::InProgress,
                'work_started_at' => now(),
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
