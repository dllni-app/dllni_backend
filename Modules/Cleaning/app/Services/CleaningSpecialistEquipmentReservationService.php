<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningSpecialistEquipmentReservationService
{
    private const BLOCKING_STATUSES = ['reserved', 'handed_over', 'acknowledged'];

    public function __construct(
        private readonly CleaningSpecialistAuthorizationService $authorizations,
    ) {}

    public function confirmForSession(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker): void
    {
        DB::transaction(function () use ($booking, $session, $worker): void {
            $lines = $booking->specialServices()
            ->where(function ($query) use ($session): void {
                $query->where('cleaning_booking_session_id', $session->id)
                    ->orWhereHas('sessions', fn ($sessions) => $sessions->whereKey($session->id))
                    ->orWhere(function ($other): void {
                        $other->whereNull('cleaning_booking_session_id')->whereDoesntHave('sessions');
                    });
            })
            ->orderBy('id')->lockForUpdate()->get();

            foreach ($lines as $line) {
                $this->confirmLine($line, $booking, $worker, $session);
            }
        }, 3);
    }

    public function confirmForBooking(CleaningBooking $booking, Worker $worker): void
    {
        DB::transaction(function () use ($booking, $worker): void {
            foreach ($booking->specialServices()->orderBy('id')->lockForUpdate()->get() as $line) {
                if ($line->cleaning_booking_session_id !== null || $line->sessions()->exists()) {
                    continue;
                }
                $this->confirmLine($line, $booking, $worker, null);
            }
        }, 3);
    }

    private function confirmLine(
        CleaningBookingSpecialService $line,
        CleaningBooking $booking,
        Worker $worker,
        ?CleaningBookingSession $session,
    ): void {
        if (in_array((string) $line->execution_status, ['completed', 'unable'], true)) {
            return;
        }
        if ($line->assigned_worker_id !== null && (int) $line->assigned_worker_id !== (int) $worker->id) {
            return;
        }

        $service = $line->specialService()->with('equipment')->firstOrFail();
        $this->authorizations->assertAllowed($worker, $service);

        $start = $session?->startsAt()
            ?? $line->session?->startsAt()
            ?? CarbonImmutable::parse($booking->scheduled_date->toDateString().' '.(string) $booking->scheduled_time, config('app.timezone'));
        if ($start === null) {
            throw ValidationException::withMessages(['equipment' => ['Cannot reserve equipment without a valid schedule.']]);
        }

        foreach ($service->equipment->sortBy('id') as $definition) {
            // Lock the asset itself, not just matching reservations: locking an empty
            // range cannot serialize two concurrent first reservations on MySQL.
            $equipment = CleaningSpecialServiceEquipment::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            if (! $equipment->is_active || $equipment->status !== 'available') {
                throw ValidationException::withMessages(['equipment' => ['Required equipment is unavailable.']]);
            }
            if (! $this->authorizations->authorizedForEquipment($worker, $equipment)) {
                throw ValidationException::withMessages(['equipment' => ['Equipment authorization is missing or inactive.']]);
            }

            $from = $start->copy()->subMinutes((int) $equipment->buffer_before_minutes);
            $until = $start->copy()->addMinutes(
                max(15, (int) $service->estimated_duration_minutes) + (int) $equipment->buffer_after_minutes
            );
            $existing = CleaningEquipmentReservation::query()
                ->where('cleaning_booking_special_service_id', $line->id)
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->where('cleaning_booking_session_id', $session?->id)
                ->lockForUpdate()->first();

            if ($existing && in_array($existing->status, self::BLOCKING_STATUSES, true)) {
                if ((int) $existing->worker_id !== (int) $worker->id) {
                    throw ValidationException::withMessages(['equipment' => ['Equipment belongs to another assigned worker.']]);
                }
                if ($existing->status !== 'reserved' && (
                    $existing->reserved_from?->ne($from) || $existing->reserved_until?->ne($until)
                )) {
                    throw ValidationException::withMessages(['equipment' => ['Equipment already handed over cannot be rescheduled.']]);
                }
            } elseif ($existing && $existing->status !== 'released') {
                throw ValidationException::withMessages(['equipment' => ['Equipment reservation must be explicitly renewed.']]);
            }

            $overlap = CleaningEquipmentReservation::query()
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->whereIn('status', self::BLOCKING_STATUSES)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                ->where('reserved_from', '<', $until)
                ->where('reserved_until', '>', $from)
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['equipment' => ['Required equipment is already reserved for this time window.']]);
            }

            if ($existing) {
                $existing->forceFill([
                    'reserved_from' => $from, 'reserved_until' => $until,
                    'cleaning_booking_session_id' => $session?->id, 'worker_id' => $worker->id,
                    'status' => 'reserved', 'handed_over_at' => null,
                    'acknowledged_at' => null, 'returned_at' => null, 'failure_reason' => null,
                ])->save();
            } else {
                CleaningEquipmentReservation::query()->create([
                    'cleaning_special_service_equipment_id' => $equipment->id,
                    'cleaning_booking_special_service_id' => $line->id,
                    'cleaning_booking_session_id' => $session?->id,
                    'worker_id' => $worker->id,
                    'reserved_from' => $from, 'reserved_until' => $until, 'status' => 'reserved',
                ]);
            }
        }

        if ($line->assigned_worker_id === null) {
            $line->forceFill(['assigned_worker_id' => $worker->id])->save();
        }
    }

    public function assertReservedForExecution(CleaningBookingSpecialService $line, Worker $worker): void
    {
        $service = $line->specialService()->with('equipment')->firstOrFail();
        $this->authorizations->assertAllowed($worker, $service);
        foreach ($service->equipment as $equipment) {
            $reservation = CleaningEquipmentReservation::query()
                ->where('cleaning_booking_special_service_id', $line->id)
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->where('worker_id', $worker->id)
                ->whereIn('status', self::BLOCKING_STATUSES)
                ->first();
            if ($reservation === null) {
                throw ValidationException::withMessages(['equipment' => ['Equipment must be reserved at worker assignment before execution.']]);
            }
        }
    }

    public function releaseForSessionWorker(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): void {
        DB::transaction(function () use ($booking, $session, $worker): void {
            $lines = $booking->specialServices()
                ->where('assigned_worker_id', $worker->id)
                ->where('execution_status', 'pending')
                ->lockForUpdate()->get();
            foreach ($lines as $line) {
                $reservations = $line->equipmentReservations()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->lockForUpdate()->get();
                if ($reservations->contains(fn ($row): bool => in_array($row->status, ['handed_over', 'acknowledged'], true))) {
                    throw ValidationException::withMessages(['equipment' => ['Returned equipment must be confirmed before releasing this session.']]);
                }
                foreach ($reservations as $reservation) {
                    if ($reservation->status === 'reserved') {
                        $reservation->forceFill(['status' => 'released'])->save();
                    }
                }
            }
        }, 3);
    }

    public function releaseIfNoActiveSessions(CleaningBooking $booking, Worker $worker): void
    {
        $stillAssigned = \Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment::query()
            ->where('worker_id', $worker->id)
            ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::activeValues())
            ->whereHas('session', fn ($query) => $query->where('cleaning_booking_id', $booking->id))
            ->exists();
        if (! $stillAssigned) {
            $this->releaseUnstartedForWorker($booking, $worker);
        }
    }

    public function releaseUnstartedForWorker(CleaningBooking $booking, Worker $worker): void
    {
        DB::transaction(function () use ($booking, $worker): void {
            $lines = $booking->specialServices()
                ->where('assigned_worker_id', $worker->id)
                ->where('execution_status', 'pending')
                ->lockForUpdate()->get();
            foreach ($lines as $line) {
                $hasInUse = $line->equipmentReservations()->whereIn('status', ['handed_over', 'acknowledged'])->exists();
                if ($hasInUse) {
                    throw ValidationException::withMessages(['equipment' => ['Equipment must be returned before worker reassignment.']]);
                }
                $line->equipmentReservations()->where('status', 'reserved')->update(['status' => 'released']);
                $line->forceFill(['assigned_worker_id' => null])->save();
            }
        }, 3);
    }
}
