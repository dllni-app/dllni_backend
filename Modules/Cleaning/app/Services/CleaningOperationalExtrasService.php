<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningOperationalExtrasService
{
    public function receiveMaterialKit(CleaningBooking $booking, Worker $worker): CleaningBookingMaterialKit
    {
        $this->assertAssigned($booking, $worker);

        return DB::transaction(function () use ($booking, $worker): CleaningBookingMaterialKit {
            $kit = CleaningBookingMaterialKit::query()
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($kit->status === CleaningBookingMaterialKit::RECEIVED) {
                return $kit;
            }
            if ($kit->status !== CleaningBookingMaterialKit::READY) {
                throw ValidationException::withMessages(['kit' => ['The material kit is not ready for handover.']]);
            }
            $kit->forceFill([
                'status' => CleaningBookingMaterialKit::RECEIVED,
                'received_by_worker_id' => $worker->id,
                'received_at' => now(),
            ])->save();

            return $kit->fresh() ?? $kit;
        });
    }

    public function startSpecialService(CleaningBookingSpecialService $line, Worker $worker): CleaningBookingSpecialService
    {
        return DB::transaction(function () use ($line, $worker): CleaningBookingSpecialService {
            $locked = CleaningBookingSpecialService::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $booking = CleaningBooking::query()->findOrFail($locked->cleaning_booking_id);
            $this->assertAssigned($booking, $worker);
            $this->assertQualified($locked, $worker);

            if ($locked->assigned_worker_id !== null && (int) $locked->assigned_worker_id !== (int) $worker->id) {
                abort(403, 'This special service is assigned to another worker.');
            }
            if ($locked->execution_status === 'in_progress') {
                return $locked->fresh(['items', 'equipmentReservations.equipment']) ?? $locked;
            }
            if (in_array($locked->execution_status, ['completed', 'unable'], true)) {
                throw ValidationException::withMessages(['service' => ['This special service is already terminal.']]);
            }

            $locked->forceFill([
                'assigned_worker_id' => $worker->id,
                'execution_status' => 'in_progress',
                'started_at' => $locked->started_at ?? now(),
            ])->save();
            $this->reserveRequiredEquipment($locked, $booking, $worker);

            return $locked->fresh(['items', 'equipmentReservations.equipment']) ?? $locked;
        }, 3);
    }

    public function finishSpecialService(
        CleaningBookingSpecialService $line,
        Worker $worker,
        string $status,
        ?string $reason,
        array $afterImages,
    ): CleaningBookingSpecialService {
        return DB::transaction(function () use ($line, $worker, $status, $reason, $afterImages): CleaningBookingSpecialService {
            $locked = CleaningBookingSpecialService::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $booking = CleaningBooking::query()->findOrFail($locked->cleaning_booking_id);
            $this->assertAssigned($booking, $worker);
            if ((int) $locked->assigned_worker_id !== (int) $worker->id) {
                abort(403, 'This special service is assigned to another worker.');
            }
            $status = $status === 'completed' ? 'completed' : 'unable';
            if (in_array($locked->execution_status, ['completed', 'unable'], true)) {
                if ($locked->execution_status === $status) {
                    return $locked->fresh(['items', 'equipmentReservations.equipment']) ?? $locked;
                }

                throw ValidationException::withMessages(['service' => ['This special service already has a different terminal status.']]);
            }
            if ($locked->execution_status !== 'in_progress') {
                throw ValidationException::withMessages(['service' => ['Start the special service before finishing it.']]);
            }
            if ($status === 'unable' && ! filled($reason)) {
                throw ValidationException::withMessages(['reason' => ['A reason is required when a service cannot be completed.']]);
            }
            $locked->forceFill([
                'execution_status' => $status,
                'unable_reason' => $status === 'unable' ? mb_substr((string) $reason, 0, 2000) : null,
                'completed_at' => now(),
            ])->save();

            if ($afterImages !== []) {
                $first = $locked->items()->orderBy('id')->first();
                $first?->forceFill(['after_images' => array_values($afterImages)])->save();
            }

            return $locked->fresh(['items', 'equipmentReservations.equipment']) ?? $locked;
        });
    }

    public function acknowledgeEquipment(CleaningEquipmentReservation $reservation, Worker $worker): CleaningEquipmentReservation
    {
        return DB::transaction(function () use ($reservation, $worker): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->worker_id === (int) $worker->id, 403, 'Equipment is assigned to another worker.');
            if ($locked->status === 'acknowledged') {
                return $locked->fresh() ?? $locked;
            }
            if (! in_array($locked->status, ['reserved', 'handed_over', 'acknowledged'], true)) {
                throw ValidationException::withMessages(['equipment' => ['Equipment cannot be acknowledged in its current state.']]);
            }
            $locked->forceFill(['status' => 'acknowledged', 'acknowledged_at' => $locked->acknowledged_at ?? now()])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function returnEquipment(CleaningEquipmentReservation $reservation, Worker $worker, ?string $failureReason): CleaningEquipmentReservation
    {
        return DB::transaction(function () use ($reservation, $worker, $failureReason): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->worker_id === (int) $worker->id, 403, 'Equipment is assigned to another worker.');
            $failed = filled($failureReason);
            $targetStatus = $failed ? 'failed' : 'returned';
            if (in_array($locked->status, ['returned', 'failed'], true)) {
                if ($locked->status === $targetStatus) {
                    return $locked->fresh() ?? $locked;
                }

                throw ValidationException::withMessages(['equipment' => ['Equipment already has a different terminal status.']]);
            }
            if (! in_array($locked->status, ['reserved', 'handed_over', 'acknowledged'], true)) {
                throw ValidationException::withMessages(['equipment' => ['Equipment cannot be returned in its current state.']]);
            }
            $locked->forceFill([
                'status' => $targetStatus,
                'returned_at' => now(),
                'failure_reason' => $failed ? mb_substr((string) $failureReason, 0, 2000) : null,
            ])->save();
            $equipment = CleaningSpecialServiceEquipment::query()->whereKey($locked->cleaning_special_service_equipment_id)->lockForUpdate()->first();
            $equipment?->forceFill([
                'status' => $failed ? 'broken' : 'available',
                'last_returned_at' => now(),
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    private function reserveRequiredEquipment(CleaningBookingSpecialService $line, CleaningBooking $booking, Worker $worker): void
    {
        $service = $line->specialService()->with('equipment')->firstOrFail();
        $session = $line->session()->first() ?? $line->sessions()->orderBy('sequence')->first();
        $start = $session?->startsAt()
            ?? CarbonImmutable::parse($booking->scheduled_date->toDateString().' '.(string) $booking->scheduled_time, config('app.timezone'));
        $duration = max(15, (int) $service->estimated_duration_minutes);

        foreach ($service->equipment as $equipment) {
            if ($equipment->status !== 'available') {
                throw ValidationException::withMessages(['equipment' => ["Required equipment {$equipment->name} is not available."]]);
            }
            $hasAuthorizationRules = DB::table('cleaning_worker_equipment_authorizations')
                ->where('cleaning_special_service_equipment_id', $equipment->id)->exists();
            $authorized = DB::table('cleaning_worker_equipment_authorizations')
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->where('worker_id', $worker->id)->where('is_active', true)->exists();
            if ($hasAuthorizationRules && ! $authorized) {
                throw ValidationException::withMessages(['equipment' => ['Worker is not authorized to use required equipment.']]);
            }

            $from = $start->subMinutes((int) $equipment->buffer_before_minutes);
            $until = $start->addMinutes($duration + (int) $equipment->buffer_after_minutes);
            $overlap = CleaningEquipmentReservation::query()
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->whereIn('status', ['reserved', 'handed_over', 'acknowledged'])
                ->where('reserved_from', '<', $until)
                ->where('reserved_until', '>', $from)
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['equipment' => ["Required equipment {$equipment->name} is already reserved."]]);
            }

            CleaningEquipmentReservation::query()->firstOrCreate([
                'cleaning_special_service_equipment_id' => $equipment->id,
                'cleaning_booking_special_service_id' => $line->id,
            ], [
                'cleaning_booking_session_id' => $session?->id,
                'worker_id' => $worker->id,
                'reserved_from' => $from,
                'reserved_until' => $until,
                'status' => 'reserved',
            ]);
        }
    }

    private function assertQualified(CleaningBookingSpecialService $line, Worker $worker): void
    {
        $serviceId = (int) $line->cleaning_special_service_id;
        $hasConfiguredSkills = DB::table('cleaning_worker_special_service_skills')
            ->where('cleaning_special_service_id', $serviceId)->exists();
        $qualified = DB::table('cleaning_worker_special_service_skills')
            ->where('cleaning_special_service_id', $serviceId)
            ->where('worker_id', $worker->id)->where('is_active', true)->exists();
        if ($hasConfiguredSkills && ! $qualified) {
            throw ValidationException::withMessages(['worker' => ['Worker is not qualified for this special service.']]);
        }
    }

    private function assertAssigned(CleaningBooking $booking, Worker $worker): void
    {
        $direct = (int) ($booking->worker_id ?? 0) === (int) $worker->id;
        $assigned = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->exists();
        $sessionAssigned = $booking->sessions()->whereHas('workerAssignments', fn ($query) => $query
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues()))->exists();
        abort_unless($direct || $assigned || $sessionAssigned, 403, 'Booking is not assigned to this worker.');
    }
}
