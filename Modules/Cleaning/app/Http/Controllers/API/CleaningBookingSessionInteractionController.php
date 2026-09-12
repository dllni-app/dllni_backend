<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\DisputeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionInteractionService;

final class CleaningBookingSessionInteractionController
{
    public function __construct(
        private readonly CleaningBookingSessionInteractionService $interactions,
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function review(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'workerId' => ['required', 'integer', 'exists:workers,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $this->interactions->submitReview(
            $cleaning_booking,
            $cleaning_booking_session,
            (int) $request->user()->id,
            $validated,
        );

        return $this->payload($cleaning_booking, [
            'reviewId' => (int) $review->id,
            'workerId' => (int) $review->worker_id,
            'rating' => (int) $review->rating,
        ]);
    }

    public function openDispute(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'category' => ['required', Rule::enum(DisputeCategory::class)],
        ]);

        $category = DisputeCategory::tryFrom((string) $validated['category']);
        if (! $category instanceof DisputeCategory) {
            throw ValidationException::withMessages(['category' => ['Invalid dispute category.']]);
        }

        $dispute = $this->interactions->openDispute(
            $cleaning_booking,
            $cleaning_booking_session,
            (int) $request->user()->id,
            (string) $validated['description'],
            $category,
        );

        return $this->payload($cleaning_booking, [
            'disputeId' => (int) $dispute->id,
            'ticketNumber' => $dispute->ticket_number,
            'status' => $dispute->status?->value ?? (string) $dispute->status,
        ], 201);
    }

    /** @param array<string, mixed> $interaction */
    private function payload(CleaningBooking $booking, array $interaction, int $status = 200): JsonResponse
    {
        $freshBooking = $booking->fresh();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $freshBooking->id,
                'bookingId' => (int) $freshBooking->id,
                'bookingNumber' => $freshBooking->booking_number,
                'status' => $freshBooking->status?->value ?? (string) $freshBooking->status,
                'totalPrice' => (float) $freshBooking->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $this->presenter->present($freshBooking),
                'interaction' => $interaction,
            ],
        ], $status);
    }
}
