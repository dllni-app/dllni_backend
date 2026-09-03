<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\User\Services\EventAssistanceReviewService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingScheduleController
{
    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
        private readonly EventAssistanceReviewService $eventReviewService,
    ) {}

    public function __invoke(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $user = $request->user();
        $worker = $user?->worker;
        $isCustomer = $user !== null && (int) $cleaning_booking->customer_id === (int) $user->id;

        if (! $isCustomer && ! $worker instanceof Worker) {
            abort(403, 'You are not allowed to view this cleaning booking schedule.');
        }

        $bookingId = (int) $cleaning_booking->id;
        $bookingNumber = (string) $cleaning_booking->booking_number;
        $isEvent = (string) $cleaning_booking->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
        $hasReview = $isCustomer && $isEvent
            ? $this->eventReviewService->hasReview($cleaning_booking)
            : false;
        $canReview = $isCustomer
            && $isEvent
            && $cleaning_booking->status === CleaningBookingStatus::Completed
            && ! $hasReview;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $bookingId,
                'bookingId' => $bookingId,
                'booking_id' => $bookingId,
                'bookingNumber' => $bookingNumber,
                'booking_number' => $bookingNumber,
                'status' => $cleaning_booking->status?->value ?? (string) $cleaning_booking->status,
                'hasReview' => $hasReview,
                'canReview' => $canReview,
                'schedule' => $this->presenter->present(
                    $cleaning_booking,
                    $worker instanceof Worker ? $worker : null,
                ),
            ],
        ]);
    }
}
