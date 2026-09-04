<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\User\Http\Requests\UserRecurringCleaningScheduleRevisionRequest;
use Modules\User\Services\RecurringCleaningScheduleRevisionService;

final class UserRecurringCleaningScheduleRevisionController
{
    public function preview(
        UserRecurringCleaningScheduleRevisionRequest $request,
        int $order,
        RecurringCleaningScheduleRevisionService $service,
    ): JsonResponse {
        $booking = $this->ownedBooking($request, $order);
        $revision = $service->preview(
            $booking,
            (int) $request->user()->id,
            (array) $request->validated('schedule'),
        );

        return response()->json([
            'success' => true,
            'data' => ['revision' => $revision],
        ]);
    }

    public function confirm(
        UserRecurringCleaningScheduleRevisionRequest $request,
        int $order,
        RecurringCleaningScheduleRevisionService $service,
        CleaningBookingSchedulePresenter $presenter,
    ): JsonResponse {
        $token = mb_trim((string) $request->input('revisionToken'));
        if ($token === '') {
            throw ValidationException::withMessages([
                'revisionToken' => ['معاينة التعديل مطلوبة قبل التأكيد.'],
            ]);
        }

        $booking = $this->ownedBooking($request, $order);
        $result = $service->confirm(
            $booking,
            (int) $request->user()->id,
            (array) $request->validated('schedule'),
            $token,
        );
        $fresh = $result['booking'];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $fresh->id,
                'bookingId' => (int) $fresh->id,
                'bookingNumber' => (string) $fresh->booking_number,
                'status' => $fresh->status?->value ?? (string) $fresh->status,
                'schedule' => $presenter->present($fresh),
                'revision' => $result['revision'],
            ],
        ]);
    }

    private function ownedBooking(UserRecurringCleaningScheduleRevisionRequest $request, int $order): CleaningBooking
    {
        return CleaningBooking::query()
            ->where('customer_id', (int) $request->user()->id)
            ->findOrFail($order);
    }
}
