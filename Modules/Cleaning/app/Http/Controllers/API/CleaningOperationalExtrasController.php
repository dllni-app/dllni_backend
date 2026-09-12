<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Services\CleaningOperationalExtrasService;

final class CleaningOperationalExtrasController
{
    public function receiveKit(Request $request, CleaningBooking $cleaning_booking, CleaningOperationalExtrasService $service): JsonResponse
    {
        $kit = $service->receiveMaterialKit($cleaning_booking, $this->worker($request));
        return response()->json(['success' => true, 'data' => ['materialKit' => $kit]]);
    }

    public function startService(Request $request, CleaningBookingSpecialService $bookingService, CleaningOperationalExtrasService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['specialService' => $service->startSpecialService($bookingService, $this->worker($request))]]);
    }

    public function finishService(Request $request, CleaningBookingSpecialService $bookingService, CleaningOperationalExtrasService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:completed,unable'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'afterImages' => ['sometimes', 'array', 'max:10'],
            'afterImages.*' => ['string', 'max:2048'],
        ]);
        $line = $service->finishSpecialService($bookingService, $this->worker($request), $validated['status'], $validated['reason'] ?? null, (array) ($validated['afterImages'] ?? []));
        return response()->json(['success' => true, 'data' => ['specialService' => $line]]);
    }

    public function acknowledgeEquipment(Request $request, CleaningEquipmentReservation $reservation, CleaningOperationalExtrasService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['reservation' => $service->acknowledgeEquipment($reservation, $this->worker($request))]]);
    }

    public function returnEquipment(Request $request, CleaningEquipmentReservation $reservation, CleaningOperationalExtrasService $service): JsonResponse
    {
        $validated = $request->validate(['failureReason' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => ['reservation' => $service->returnEquipment($reservation, $this->worker($request), $validated['failureReason'] ?? null)]]);
    }

    private function worker(Request $request): Worker
    {
        $worker = $request->user()?->worker;
        abort_unless($worker instanceof Worker, 403, 'User must have an associated worker.');
        return $worker;
    }
}
