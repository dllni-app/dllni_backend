<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Enums\WorkerCustomerRatingType;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class EventAssistanceReviewService
{
    /**
     * @param  array{rating:int,comment?:string|null}  $validated
     * @return array<int, WorkerCustomerRating>
     */
    public function submit(CleaningBooking $booking, array $validated): array
    {
        if ((string) $booking->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            throw ValidationException::withMessages([
                'booking' => ['This review flow is only available for event assistance bookings.'],
            ]);
        }

        if ($booking->status !== CleaningBookingStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['The event can be reviewed only after all required event days are completed.'],
            ]);
        }

        if ($this->hasReview($booking)) {
            throw ValidationException::withMessages([
                'review' => ['The event has already been reviewed.'],
            ]);
        }

        $workerIds = CleaningBookingSessionWorkerAssignment::query()
            ->whereHas('session', fn ($query) => $query->where('cleaning_booking_id', $booking->id))
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->distinct()
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->filter(static fn (int $workerId): bool => $workerId > 0)
            ->values()
            ->all();

        if ($workerIds === []) {
            $workerIds = $booking->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
                ->distinct()
                ->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
                ->filter(static fn (int $workerId): bool => $workerId > 0)
                ->values()
                ->all();
        }

        if ($workerIds === []) {
            throw ValidationException::withMessages([
                'workers' => ['No completed event-team worker is available to receive this review.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $validated, $workerIds): array {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CleaningBookingStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => ['The event can be reviewed only after all required event days are completed.'],
                ]);
            }

            if ($this->hasReview($locked)) {
                throw ValidationException::withMessages([
                    'review' => ['The event has already been reviewed.'],
                ]);
            }

            $reviews = [];
            foreach ($workerIds as $workerId) {
                $reviews[] = WorkerCustomerRating::query()->create([
                    'booking_id' => $locked->id,
                    'booking_type' => $locked->getMorphClass(),
                    'worker_id' => $workerId,
                    'customer_id' => $locked->customer_id,
                    'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
                    'rating' => (int) $validated['rating'],
                    'comment' => $validated['comment'] ?? null,
                ]);
            }

            foreach ($workerIds as $workerId) {
                $average = WorkerCustomerRating::query()
                    ->where('worker_id', $workerId)
                    ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
                    ->avg('rating');

                Worker::query()->whereKey($workerId)->update([
                    'average_rating' => $average !== null ? round((float) $average, 2) : 0,
                ]);
            }

            return $reviews;
        });
    }

    public function hasReview(CleaningBooking $booking): bool
    {
        return WorkerCustomerRating::query()
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->where('customer_id', $booking->customer_id)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->exists();
    }
}
