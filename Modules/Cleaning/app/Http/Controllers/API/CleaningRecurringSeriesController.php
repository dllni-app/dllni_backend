<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\RecurringCleaningPauseService;

final class CleaningRecurringSeriesController
{
    public function __construct(
        private readonly RecurringCleaningPauseService $pauseService,
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function pause(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->pauseService->pause(
                $cleaning_booking,
                (int) $request->user()->id,
                (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($result['booking'], [
            'action' => 'paused',
            'pausedSessionIds' => $result['pausedSessionIds'],
            'releasedWorkerIds' => $result['releasedWorkerIds'],
        ]);
    }

    public function resume(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        try {
            $result = $this->pauseService->resume(
                $cleaning_booking,
                (int) $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($result['booking'], [
            'action' => 'resumed',
            'resumedSessionIds' => $result['resumedSessionIds'],
            'expiredSessionIds' => $result['expiredSessionIds'],
        ]);
    }

    /** @param array<string, mixed> $seriesAction */
    private function payload(CleaningBooking $booking, array $seriesAction): JsonResponse
    {
        $fresh = $booking->fresh() ?? $booking;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $fresh->id,
                'bookingId' => (int) $fresh->id,
                'bookingNumber' => (string) $fresh->booking_number,
                'status' => $fresh->status?->value ?? (string) $fresh->status,
                'totalPrice' => (float) $fresh->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $this->presenter->present($fresh),
                'seriesAction' => $seriesAction,
            ],
        ]);
    }
}
