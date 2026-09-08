<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingCustomerScheduleService;
use Modules\User\Services\EventAssistanceSessionRescheduleService;

final class CleaningBookingSessionScheduleController
{
    public function __invoke(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
        EventAssistanceSessionRescheduleService $service,
        CleaningBookingCustomerScheduleService $customerSchedules,
    ): JsonResponse {
        $today = now(config('app.timezone'))->toDateString();
        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$today],
            'time' => ['required', 'date_format:H:i'],
            'hours' => ['sometimes', 'numeric', 'min:1', 'max:24'],
        ]);

        $updatedSession = $service->reschedule(
            booking: $cleaning_booking,
            session: $cleaning_booking_session,
            customerId: (int) $request->user()->id,
            input: $validated,
        );
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
                'updatedSessionId' => (int) $updatedSession->id,
                'schedule' => $customerSchedules->present($freshBooking),
            ],
        ]);
    }
}
