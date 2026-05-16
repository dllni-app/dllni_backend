<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Support\Broadcast\BroadcastAfterResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CleaningOrderAwaitingCustomerCompletion;
use Modules\Cleaning\Events\CleaningOrderAwaitingStartVerification;
use Modules\Cleaning\Events\WorkerArrived;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingService
{
    private const SECURITY_CODE_TTL_MINUTES = 10;

    private const SECURITY_CODE_LENGTH = 4;

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
        $updated = DB::transaction(static function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::Pending) {
                throw new InvalidArgumentException('Booking cannot be accepted in current status.');
            }

            $workerId = Auth::user()?->worker?->id;
            if ($booking->worker_id !== null && $booking->worker_id !== $workerId) {
                throw new InvalidArgumentException('Booking is assigned to another worker.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::WorkerAssigned,
                'worker_id' => $booking->worker_id ?? $workerId,
            ]);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function reject(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        $updated = DB::transaction(static function () use ($booking, $reason) {
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

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function startTravel(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(static function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking cannot start travel in current status.');
            }

            $booking->update(['started_travel_at' => now()]);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    /**
     * @return array{securityCode: string, expiresAt: string}
     */
    public function issueSecurityCode(CleaningBooking $booking): array
    {
        return DB::transaction(function () use ($booking): array {
            if (! in_array($booking->status, [CleaningBookingStatus::WorkerAssigned, CleaningBookingStatus::AwaitingStartVerification], true)) {
                throw new InvalidArgumentException('Security code is only available for bookings ready to start.');
            }

            $securityCode = mb_str_pad((string) random_int(0, 9999), self::SECURITY_CODE_LENGTH, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes(self::SECURITY_CODE_TTL_MINUTES);

            DB::table('booking_security_codes')->updateOrInsert(
                [
                    'booking_id' => $booking->id,
                    'booking_type' => $booking->getMorphClass(),
                ],
                [
                    'code' => $this->securityCodeHash($securityCode),
                    'code_hash' => $this->securityCodeHash($securityCode),
                    'attempts' => 0,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                    'last_attempt_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return [
                'securityCode' => $securityCode,
                'expiresAt' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function updateLocation(CleaningBooking $booking, float $latitude, float $longitude): void
    {
        if ($booking->status !== CleaningBookingStatus::WorkerAssigned || $booking->started_travel_at === null) {
            throw new InvalidArgumentException('Worker must have started travel to send location updates.');
        }

        $worker = Auth::user()?->worker;
        if (! $worker || $booking->worker_id !== $worker->id) {
            throw new InvalidArgumentException('Only the assigned worker can update location.');
        }

        BroadcastAfterResponse::send(new WorkerLocationUpdated($booking->id, $latitude, $longitude, $worker->id));
    }

    public function arrive(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking) {
            if (! in_array($booking->status, [CleaningBookingStatus::WorkerAssigned, CleaningBookingStatus::AwaitingStartVerification], true)) {
                throw new InvalidArgumentException('Booking must be in worker_assigned status to mark arrival.');
            }
            if ($booking->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::AwaitingStartVerification,
                'arrived_at' => now(),
            ]);

            return $booking->fresh();
        });

        BroadcastAfterResponse::send(new WorkerArrived($updated->id, (string) $updated->arrived_at?->toIso8601String()));
        BroadcastAfterResponse::send(new CleaningOrderAwaitingStartVerification(
            $updated->id,
            $updated->customer_id,
            $updated->worker_id,
            (string) $updated->status?->value,
            $this->activeSecurityCodeExpiresAt($updated)?->toIso8601String(),
        ));
        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function startWork(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(static function () use ($booking) {
            if ($booking->status === CleaningBookingStatus::AwaitingStartVerification) {
                $securityCode = DB::table('booking_security_codes')
                    ->where('booking_id', $booking->id)
                    ->where('booking_type', $booking->getMorphClass())
                    ->orderByDesc('id')
                    ->first();

                if (! $securityCode || $securityCode->consumed_at === null) {
                    throw new InvalidArgumentException('Customer must verify the security code before work can start.');
                }

                $booking->update([
                    'status' => CleaningBookingStatus::InProgress,
                    'work_started_at' => now(),
                ]);

                return $booking->fresh();
            }

            if ($booking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking must be assigned to start work.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::InProgress,
                'work_started_at' => now(),
            ]);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function complete(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(static function () use ($booking) {
            if ($booking->status !== CleaningBookingStatus::InProgress) {
                throw new InvalidArgumentException('Booking must be in progress to mark completion.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                'work_finished_at' => now(),
            ]);

            return $booking->fresh();
        });

        BroadcastAfterResponse::send(new CleaningOrderAwaitingCustomerCompletion(
            $updated->id,
            $updated->worker_id,
            (string) $updated->status?->value,
            now()->addMinutes(30)->toIso8601String(),
        ));
        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        $updated = DB::transaction(static function () use ($booking, $reason) {
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

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $booking->status?->value,
            'workerId' => $booking->worker_id,
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    private function activeSecurityCodeExpiresAt(CleaningBooking $booking): ?\Carbon\CarbonInterface
    {
        $record = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->orderByDesc('id')
            ->first();

        if (! $record || $record->expires_at === null) {
            return null;
        }

        return Carbon::parse($record->expires_at);
    }

    private function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
