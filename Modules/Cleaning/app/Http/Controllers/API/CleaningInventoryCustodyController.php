<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;
use Modules\Cleaning\Services\CleaningGroupCustodyPolicy;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\SupervisedTeamService;

final class CleaningInventoryCustodyController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function showWorker(Request $request, int $booking): JsonResponse
    {
        $worker = $request->user()?->worker;
        abort_unless($worker instanceof Worker, 403, 'Worker account required.');
        $row = CleaningBooking::query()->findOrFail($booking);
        $assigned = (int) $row->worker_id === (int) $worker->id || $row->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->exists();
        abort_unless($assigned, 404, 'Booking not found.');

        // Materials for a supervised group are issued to its supervisor. Workers
        // see only individually assigned reusable assets in that case.
        return response()->json(['data' => $this->inventory($row, $worker, app(CleaningGroupCustodyPolicy::class)->usesSupervisorCustody($row))]);
    }

    public function showSupervisor(Request $request, int $booking): JsonResponse
    {
        $row = $this->assignedSupervisorBooking($request, $booking);

        return response()->json(['data' => $this->inventory($row)]);
    }

    public function receiveSupervisorKit(Request $request, int $booking, CleaningMaterialInventoryService $materials): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $supervisor = $this->user($request);
        $row = $this->assignedSupervisorBooking($request, $booking);
        $kit = $materials->receiveKitForSupervisor($row, $supervisor, null, (string) ($validated['note'] ?? 'Supervisor acknowledged material kit receipt'));

        return response()->json(['data' => ['materialKit' => [
            'id' => $kit->id, 'status' => $kit->status,
            'receivedBySupervisorUserId' => $kit->received_by_supervisor_user_id,
            'receivedAt' => $kit->received_at?->toIso8601String(),
        ]]]);
    }

    public function acknowledgeSupervisorEquipment(Request $request, int $reservation, CleaningEquipmentHandoverService $handover): JsonResponse
    {
        $record = CleaningEquipmentReservation::query()->findOrFail($reservation);
        $supervisor = $this->user($request);
        app(CleaningGroupCustodyPolicy::class)->assertActiveSupervisor($this->reservationBooking($record), $supervisor);
        $result = $handover->acknowledgeBySupervisor($record, $supervisor);

        return response()->json(['data' => ['reservation' => $this->equipmentRow($result)]]);
    }

    public function returnSupervisorEquipment(Request $request, int $reservation, CleaningEquipmentHandoverService $handover): JsonResponse
    {
        $validated = $request->validate(['failureReason' => ['nullable', 'string', 'max:2000']]);
        $record = CleaningEquipmentReservation::query()->findOrFail($reservation);
        $supervisor = $this->user($request);
        $result = $handover->reportReturnBySupervisor($record, $supervisor, $validated['failureReason'] ?? null);

        return response()->json(['data' => ['reservation' => $this->equipmentRow($result)]]);
    }

    private function inventory(CleaningBooking $booking, ?Worker $worker = null, bool $hideGroupMaterials = false): array
    {
        $booking->loadMissing(['materialKit', 'materials.material.materialType.unit']);
        $kit = $hideGroupMaterials ? null : $booking->materialKit;
        $materials = $hideGroupMaterials ? [] : $booking->materials->map(fn (CleaningBookingMaterial $line): array => [
            'id' => $line->id,
            'materialId' => $line->cleaning_material_id,
            'name' => $line->material?->name,
            'type' => $line->material?->materialType?->name,
            'unit' => $line->material?->materialType?->unit?->symbol,
            'issuedQuantity' => (float) $line->quantity,
            'usedQuantity' => $line->used_quantity === null ? null : (float) $line->used_quantity,
            'returnedQuantity' => $line->returned_quantity === null ? null : (float) $line->returned_quantity,
            'damagedQuantity' => $line->damaged_quantity === null ? null : (float) $line->damaged_quantity,
            'lostQuantity' => $line->lost_quantity === null ? null : (float) $line->lost_quantity,
            'status' => $line->inventory_status,
        ])->all();

        $equipment = CleaningEquipmentReservation::query()->with('equipment')
            ->where(function ($query) use ($booking): void {
                $query->where('cleaning_booking_id', $booking->id)
                    ->orWhereHas('bookingSpecialService', fn ($special) => $special->where('cleaning_booking_id', $booking->id));
            })
            ->when($worker, fn ($query) => $query->where('worker_id', $worker->id))
            ->orderBy('id')->get()
            ->map(fn (CleaningEquipmentReservation $reservation): array => $this->equipmentRow($reservation))->all();

        return [
            'bookingId' => $booking->id,
            'materialKit' => $kit ? [
                'id' => $kit->id, 'status' => $kit->status,
                'receivedByWorkerId' => $kit->received_by_worker_id,
                'receivedBySupervisorUserId' => $kit->received_by_supervisor_user_id,
                'receivedAt' => $kit->received_at?->toIso8601String(),
            ] : null,
            'materials' => $materials,
            'equipment' => $equipment,
        ];
    }

    private function equipmentRow(CleaningEquipmentReservation $reservation): array
    {
        $reservation->loadMissing('equipment');

        return [
            'reservationId' => $reservation->id,
            'equipmentId' => $reservation->cleaning_special_service_equipment_id,
            'assetCode' => $reservation->equipment?->asset_code,
            'name' => $reservation->equipment?->name,
            'status' => $reservation->status,
            'custodianType' => $reservation->supervisor_user_id === null ? 'worker' : 'supervisor',
            'workerId' => $reservation->worker_id,
            'supervisorUserId' => $reservation->supervisor_user_id,
            'reservedUntil' => $reservation->reserved_until?->toIso8601String(),
            'handedOverAt' => $reservation->handed_over_at?->toIso8601String(),
            'acknowledgedAt' => $reservation->acknowledged_at?->toIso8601String(),
            'returnedAt' => $reservation->returned_at?->toIso8601String(),
            'returnConfirmedAt' => $reservation->return_confirmed_at?->toIso8601String(),
            'returnResolution' => $reservation->return_resolution,
            'failureReason' => $reservation->failure_reason,
        ];
    }

    private function user(Request $request): User
    {
        return $this->teams->assertSupervisor($request->user());
    }

    private function assignedSupervisorBooking(Request $request, int $bookingId): CleaningBooking
    {
        $booking = CleaningBooking::query()->findOrFail($bookingId);
        $supervisor = $this->user($request);
        app(CleaningGroupCustodyPolicy::class)->assertActiveSupervisor($booking, $supervisor);

        return $booking;
    }

    private function reservationBooking(CleaningEquipmentReservation $reservation): CleaningBooking
    {
        if ($reservation->cleaning_booking_id !== null) {
            return CleaningBooking::query()->findOrFail($reservation->cleaning_booking_id);
        }

        $bookingId = $reservation->bookingSpecialService?->cleaning_booking_id;
        abort_unless($bookingId !== null, 404, 'Booking inventory not found.');

        return CleaningBooking::query()->findOrFail($bookingId);
    }
}
