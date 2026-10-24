<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningEquipmentHandoverService
{
    public function __construct(private readonly CleaningAdministrativeOperationsService $adminOperations) {}

    /** Checkout an individually tracked reusable asset for a booking. */
    public function checkoutForBooking(CleaningBooking $booking, CleaningSpecialServiceEquipment $equipment, User $admin, string $note): CleaningEquipmentReservation
    {
        $this->adminOperations->assertOperator($admin);
        $note = mb_trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'A checkout note is required (maximum 2000 characters).']);
        }

        return DB::transaction(function () use ($booking, $equipment, $admin, $note): CleaningEquipmentReservation {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->isV2OperationalGroup()) {
                throw ValidationException::withMessages([
                    'booking' => 'Visit-scoped equipment checkout is required for V2 group bookings.',
                ]);
            }
            $asset = CleaningSpecialServiceEquipment::query()->whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            if (! $asset->is_active || $asset->status !== 'available' || CleaningEquipmentReservation::query()
                ->where('cleaning_special_service_equipment_id', $asset->id)
                ->whereIn('status', ['reserved', 'handed_over', 'acknowledged', 'return_pending_confirmation', 'failure_pending_confirmation'])
                ->exists()) {
                throw ValidationException::withMessages(['equipment' => 'The asset is already allocated or is not available.']);
            }

            $supervisorId = null;
            $workerId = null;
            if (app(CleaningGroupCustodyPolicy::class)->usesSupervisorCustody($booking)) {
                $supervisorId = $booking->supervisorAssignments()->whereNull('released_at')->value('supervisor_user_id');
                if ($supervisorId === null) {
                    throw ValidationException::withMessages(['supervisor' => 'Assign a supervisor before issuing group equipment.']);
                }
            } else {
                $workerId = $booking->worker_id ?? $booking->workerAssignments()
                    ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->value('worker_id');
                if ($workerId === null || ! Worker::query()->whereKey($workerId)->exists()) {
                    throw ValidationException::withMessages(['worker' => 'Assign a worker before issuing equipment.']);
                }
            }

            if (! $booking->scheduled_date || ! $booking->scheduled_time) {
                throw ValidationException::withMessages(['booking' => 'A scheduled date and time are required before checkout.']);
            }

            $scheduledDate = $booking->scheduled_date instanceof DateTimeInterface
                ? $booking->scheduled_date->format('Y-m-d') : (string) $booking->scheduled_date;
            $scheduled = Carbon::parse($scheduledDate.' '.(string) $booking->scheduled_time, config('app.timezone'));
            $estimatedEnd = $booking->supervised_scheduled_end_at
                ? Carbon::parse($booking->supervised_scheduled_end_at, config('app.timezone'))
                : $scheduled->addMinutes(max(60, (int) round((float) $booking->estimated_hours * 60)));
            $due = $estimatedEnd->isFuture() ? $estimatedEnd : now()->addDay();

            $reservation = CleaningEquipmentReservation::query()->create([
                'cleaning_booking_id' => $booking->id,
                'cleaning_special_service_equipment_id' => $asset->id,
                'supervisor_user_id' => $supervisorId,
                'worker_id' => $workerId,
                'reserved_from' => now(),
                'reserved_until' => $due,
                'status' => 'reserved',
            ]);

            return $this->handover($reservation, $admin, $note);
        }, 3);
    }

    public function handover(CleaningEquipmentReservation $reservation, User $admin, string $note): CleaningEquipmentReservation
    {
        $this->adminOperations->assertOperator($admin);

        return DB::transaction(function () use ($reservation, $admin, $note): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'handed_over' || $locked->status === 'acknowledged') {
                return $locked;
            }
            if ($locked->status !== 'reserved') {
                throw ValidationException::withMessages(['equipment' => ['Only reserved equipment can be handed over.']]);
            }
            if ($locked->worker_id === null && $locked->supervisor_user_id === null) {
                throw ValidationException::withMessages(['worker' => ['A worker or supervisor must be assigned before equipment handover.']]);
            }
            $asset = CleaningSpecialServiceEquipment::query()->whereKey($locked->cleaning_special_service_equipment_id)->lockForUpdate()->firstOrFail();
            if (! $asset->is_active || $asset->status !== 'available') {
                throw ValidationException::withMessages(['equipment' => ['Equipment is not in an available and active condition.']]);
            }
            $old = $locked->status;
            $locked->forceFill([
                'status' => 'handed_over',
                'handed_over_at' => now(),
                'handed_over_by_user_id' => $admin->id,
            ])->save();
            $asset->forceFill(['status' => 'in_use', 'last_handed_over_at' => now()])->save();
            $this->audit($locked, $admin, 'equipment_handed_over', $note, $old, $locked->status);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function reportGroupReturn(CleaningEquipmentReservation $reservation, User $admin, bool $damaged, string $note): CleaningEquipmentReservation
    {
        $this->adminOperations->assertOperator($admin);
        $note = mb_trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'A return report is required.']);
        }

        return DB::transaction(function () use ($reservation, $admin, $damaged, $note): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->supervisor_user_id === null || $locked->cleaning_booking_id === null) {
                throw ValidationException::withMessages(['equipment' => 'Only supervisor-held booking assets support an administrative return report.']);
            }
            $target = $damaged ? 'failure_pending_confirmation' : 'return_pending_confirmation';
            if ($locked->status === $target) {
                return $locked;
            }
            if (! in_array($locked->status, ['handed_over', 'acknowledged'], true)) {
                throw ValidationException::withMessages(['equipment' => 'This asset is not currently checked out.']);
            }
            $previous = $locked->status;
            $locked->forceFill(['status' => $target, 'returned_at' => now(), 'failure_reason' => $damaged ? $note : null])->save();
            $this->audit($locked, $admin, 'equipment_return_reported', $note, $previous, $target);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function acknowledgeBySupervisor(CleaningEquipmentReservation $reservation, User $supervisor): CleaningEquipmentReservation
    {
        return DB::transaction(function () use ($reservation, $supervisor): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveSupervisorCustody($locked, $supervisor, false);
            if ($locked->status === 'acknowledged') {
                return $locked;
            }
            if ($locked->status !== 'handed_over' || $locked->handed_over_at === null) {
                throw ValidationException::withMessages(['equipment' => 'The equipment must be handed over before acknowledgement.']);
            }
            $locked->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now()])->save();
            $this->audit($locked, $supervisor, 'equipment_acknowledged', 'Supervisor acknowledged asset custody', 'handed_over', 'acknowledged');

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function reportReturnBySupervisor(CleaningEquipmentReservation $reservation, User $supervisor, ?string $failureReason): CleaningEquipmentReservation
    {
        return DB::transaction(function () use ($reservation, $supervisor, $failureReason): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveSupervisorCustody($locked, $supervisor, true);
            $damaged = filled($failureReason);
            $status = $damaged ? 'failure_pending_confirmation' : 'return_pending_confirmation';
            if ($locked->status === $status) {
                return $locked;
            }
            if ($locked->status !== 'acknowledged') {
                throw ValidationException::withMessages(['equipment' => 'Acknowledge custody before reporting the return.']);
            }
            $locked->forceFill([
                'status' => $status,
                'returned_at' => now(),
                'failure_reason' => $damaged ? mb_substr((string) $failureReason, 0, 2000) : null,
            ])->save();
            $this->audit($locked, $supervisor, 'equipment_return_reported',
                $damaged ? (string) $failureReason : 'Supervisor reported a complete return', 'acknowledged', $status);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function recoverLostEquipment(CleaningSpecialServiceEquipment $asset, User $admin, string $note): CleaningSpecialServiceEquipment
    {
        $this->adminOperations->assertOperator($admin);
        $note = mb_trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'A recovery inspection note is required.']);
        }

        return DB::transaction(function () use ($asset, $admin, $note): CleaningSpecialServiceEquipment {
            $locked = CleaningSpecialServiceEquipment::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'lost') {
                throw ValidationException::withMessages(['equipment' => 'Only assets confirmed as lost may be recovered.']);
            }
            $reservation = CleaningEquipmentReservation::query()
                ->where('cleaning_special_service_equipment_id', $locked->id)
                ->where('status', 'lost')->latest('id')->firstOrFail();
            // The recovery action has already checked the physical asset and
            // retains the original lost reservation as historical evidence.
            $locked->forceFill(['status' => 'available', 'last_returned_at' => now()])->saveQuietly();
            $this->audit($reservation, $admin, 'equipment_recovered', $note, 'lost', 'available');

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function confirmReturn(
        CleaningEquipmentReservation $reservation,
        User $admin,
        string $note,
        ?string $resolution = null,
    ): CleaningEquipmentReservation {
        $this->adminOperations->assertOperator($admin);
        $note = mb_trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => ['Administrative return confirmation requires a note.']]);
        }

        return DB::transaction(function () use ($reservation, $admin, $note, $resolution): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['returned', 'failed', 'lost'], true) && $locked->return_confirmed_at !== null) {
                return $locked;
            }
            $lost = $resolution === 'lost';
            if (! in_array($locked->status, ['return_pending_confirmation', 'failure_pending_confirmation'], true)
                && ! ($lost && in_array($locked->status, ['handed_over', 'acknowledged'], true))) {
                throw ValidationException::withMessages(['equipment' => ['Worker must first report equipment return.']]);
            }
            if ($resolution !== null && ! in_array($resolution, ['accepted', 'maintenance', 'lost'], true)) {
                throw ValidationException::withMessages(['resolution' => 'Invalid return outcome.']);
            }
            $asset = CleaningSpecialServiceEquipment::query()->whereKey($locked->cleaning_special_service_equipment_id)->lockForUpdate()->firstOrFail();
            $failed = $locked->status === 'failure_pending_confirmation' || $resolution === 'maintenance';
            if (! $lost && (($locked->status === 'failure_pending_confirmation') !== $failed)) {
                throw ValidationException::withMessages(['resolution' => 'Damage outcome does not match the reported asset condition.']);
            }
            $previous = $locked->status;
            $locked->forceFill([
                'status' => $lost ? 'lost' : ($failed ? 'failed' : 'returned'),
                'return_confirmed_at' => now(),
                'return_confirmed_by_user_id' => $admin->id,
                'return_resolution' => $lost ? 'lost' : ($failed ? 'maintenance' : 'accepted'),
                'return_admin_note' => mb_substr($note, 0, 2000),
            ])->save();
            $asset->forceFill([
                'status' => $lost ? 'lost' : ($failed ? 'broken' : 'available'),
                'last_returned_at' => $lost ? $asset->last_returned_at : ($locked->returned_at ?? now()),
            ])->save();
            $this->audit($locked, $admin, 'equipment_return_confirmed', $note, $previous, $locked->status);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    private function assertActiveSupervisorCustody(
        CleaningEquipmentReservation $reservation,
        User $supervisor,
        bool $allowExistingV2Return,
    ): void
    {
        if ($reservation->supervisor_user_id === null || (int) $reservation->supervisor_user_id !== (int) $supervisor->id
            || $reservation->cleaning_booking_id === null) {
            abort(404, 'Equipment custody was not found.');
        }
        $booking = CleaningBooking::query()->findOrFail($reservation->cleaning_booking_id);
        if ($booking->isV2OperationalGroup() && $allowExistingV2Return) {
            return;
        }

        app(CleaningGroupCustodyPolicy::class)->assertActiveSupervisor($booking, $supervisor);
    }

    private function audit(
        CleaningEquipmentReservation $reservation, User $admin, string $action,
        string $reason, string $before, string $after
    ): void {
        DB::table('cleaning_operational_action_audits')->insert([
            'action' => $action,
            'cleaning_booking_id' => $reservation->cleaning_booking_id ?? $reservation->bookingSpecialService?->cleaning_booking_id,
            'cleaning_booking_session_id' => $reservation->cleaning_booking_session_id,
            'cleaning_equipment_reservation_id' => $reservation->id,
            'actor_user_id' => $admin->id,
            'reason' => $reason,
            'before_snapshot' => json_encode(['status' => $before], JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode(['status' => $after, 'assetId' => $reservation->cleaning_special_service_equipment_id, 'workerId' => $reservation->worker_id, 'supervisorUserId' => $reservation->supervisor_user_id], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
