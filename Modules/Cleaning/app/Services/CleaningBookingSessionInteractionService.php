<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\WorkerCustomerRatingType;
use App\Models\Dispute;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionInteractionService
{
    /** @param array{workerId:int,rating:int,comment?:string|null} $validated */
    public function submitReview(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        array $validated,
    ): WorkerCustomerRating {
        $this->assertCustomerSession($booking, $session, $customerId);

        if ($this->sessionStatus($session) !== CleaningBookingSessionStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['A session can only be reviewed after customer-confirmed completion.'],
            ]);
        }

        $workerId = (int) $validated['workerId'];
        $completedAssignment = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $workerId)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->exists();

        if (! $completedAssignment) {
            throw ValidationException::withMessages([
                'workerId' => ['This worker did not complete this session.'],
            ]);
        }

        $review = WorkerCustomerRating::query()->updateOrCreate(
            [
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'cleaning_booking_session_id' => $session->id,
                'worker_id' => $workerId,
                'customer_id' => $booking->customer_id,
                'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
            ],
            [
                'rating' => (int) $validated['rating'],
                'comment' => $this->nullableTrimmed($validated['comment'] ?? null),
            ],
        );

        $this->syncWorkerAverageRating($workerId);

        return $review;
    }

    public function openDispute(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $description,
        DisputeCategory $category,
    ): Dispute {
        $this->assertCustomerSession($booking, $session, $customerId);

        if (! in_array($this->sessionStatus($session), [
            CleaningBookingSessionStatus::Completed,
            CleaningBookingSessionStatus::Cancelled,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['A session dispute can only be opened after completion or cancellation.'],
            ]);
        }

        $activeExists = Dispute::query()
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->where('cleaning_booking_session_id', $session->id)
            ->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::UnderReview->value])
            ->exists();

        if ($activeExists) {
            throw ValidationException::withMessages([
                'dispute' => ['This session already has an active dispute.'],
            ]);
        }

        return Dispute::query()->create([
            'booking_id' => $booking->id,
            'booking_type' => $booking->getMorphClass(),
            'cleaning_booking_session_id' => $session->id,
            'ticket_number' => 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'description' => mb_trim($description),
            'category' => $category->value,
            'status' => DisputeStatus::Open->value,
            'resolution' => null,
            'worker_earnings_frozen' => false,
        ]);
    }

    private function assertCustomerSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
    ): void {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        if ((int) $session->cleaning_booking_id !== (int) $booking->id) {
            throw ValidationException::withMessages([
                'sessionId' => ['Session does not belong to this booking.'],
            ]);
        }
    }

    private function sessionStatus(CleaningBookingSession $session): ?CleaningBookingSessionStatus
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status
            : CleaningBookingSessionStatus::tryFrom((string) $session->status);
    }

    private function syncWorkerAverageRating(int $workerId): void
    {
        $average = WorkerCustomerRating::query()
            ->where('worker_id', $workerId)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->avg('rating');

        Worker::query()->whereKey($workerId)->update([
            'average_rating' => $average !== null ? round((float) $average, 2) : 0,
        ]);
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
