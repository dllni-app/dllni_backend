<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningEquipmentHandoverService
{
    public function __construct(private readonly CleaningAdministrativeOperationsService $adminOperations) {}

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
            if ($locked->worker_id === null) {
                throw ValidationException::withMessages(['worker' => ['A worker must be assigned before equipment handover.']]);
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

    public function confirmReturn(
        CleaningEquipmentReservation $reservation,
        User $admin,
        string $note,
    ): CleaningEquipmentReservation {
        $this->adminOperations->assertOperator($admin);
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => ['Administrative return confirmation requires a note.']]);
        }
        return DB::transaction(function () use ($reservation, $admin, $note): CleaningEquipmentReservation {
            $locked = CleaningEquipmentReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['returned', 'failed'], true) && $locked->return_confirmed_at !== null) {
                return $locked;
            }
            if (! in_array($locked->status, ['return_pending_confirmation', 'failure_pending_confirmation'], true)) {
                throw ValidationException::withMessages(['equipment' => ['Worker must first report equipment return.']]);
            }
            $asset = CleaningSpecialServiceEquipment::query()->whereKey($locked->cleaning_special_service_equipment_id)->lockForUpdate()->firstOrFail();
            $failed = $locked->status === 'failure_pending_confirmation';
            $previous = $locked->status;
            $locked->forceFill([
                'status' => $failed ? 'failed' : 'returned',
                'return_confirmed_at' => now(),
                'return_confirmed_by_user_id' => $admin->id,
                'return_resolution' => $failed ? 'maintenance' : 'accepted',
                'return_admin_note' => mb_substr($note, 0, 2000),
            ])->save();
            $asset->forceFill([
                'status' => $failed ? 'broken' : 'available',
                'last_returned_at' => $locked->returned_at ?? now(),
            ])->save();
            $this->audit($locked, $admin, 'equipment_return_confirmed', $note, $previous, $locked->status);
            return $locked->fresh() ?? $locked;
        }, 3);
    }

    private function audit(
        CleaningEquipmentReservation $reservation, User $admin, string $action,
        string $reason, string $before, string $after
    ): void {
        DB::table('cleaning_operational_action_audits')->insert([
            'action' => $action,
            'cleaning_booking_id' => $reservation->bookingSpecialService?->cleaning_booking_id,
            'cleaning_booking_session_id' => $reservation->cleaning_booking_session_id,
            'cleaning_equipment_reservation_id' => $reservation->id,
            'actor_user_id' => $admin->id,
            'reason' => $reason,
            'before_snapshot' => json_encode(['status' => $before], JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode(['status' => $after, 'assetId' => $reservation->cleaning_special_service_equipment_id], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
