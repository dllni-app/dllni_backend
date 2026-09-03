<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionWorkerChangeService;

final class CleaningBookingSessionWorkerChangeController
{
    public function __invoke(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSessionWorkerChangeService $service,
        CleaningBookingSchedulePresenter $presenter,
    ): JsonResponse {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.sessionId' => ['required', 'integer', 'distinct'],
            'changes.*.workerIds' => ['required', 'array', 'min:1'],
            'changes.*.workerIds.*' => ['required', 'integer', 'distinct'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = $service->releaseSelectedFutureAssignments(
                booking: $cleaning_booking,
                customerId: (int) $request->user()->id,
                changes: $validated['changes'],
                reason: (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'changes' => [$e->getMessage()],
            ]);
        }

        $freshBooking = $cleaning_booking->fresh();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $freshBooking->id,
                'bookingId' => (int) $freshBooking->id,
                'bookingNumber' => $freshBooking->booking_number,
                'status' => $freshBooking->status?->value ?? (string) $freshBooking->status,
                'totalPrice' => (float) $freshBooking->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $presenter->present($freshBooking),
                'workerChange' => $result,
            ],
        ]);
    }
}
