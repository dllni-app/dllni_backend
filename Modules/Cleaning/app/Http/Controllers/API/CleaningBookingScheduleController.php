<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;

final class CleaningBookingScheduleController
{
    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function __invoke(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $user = $request->user();
        $worker = $user?->worker;
        $isCustomer = $user !== null && (int) $cleaning_booking->customer_id === (int) $user->id;

        if (! $isCustomer && ! $worker instanceof Worker) {
            abort(403, 'You are not allowed to view this cleaning booking schedule.');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'bookingId' => (int) $cleaning_booking->id,
                'bookingNumber' => (string) $cleaning_booking->booking_number,
                'schedule' => $this->presenter->present(
                    $cleaning_booking,
                    $worker instanceof Worker ? $worker : null,
                ),
            ],
        ]);
    }
}
