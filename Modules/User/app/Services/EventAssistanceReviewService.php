<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Enums\WorkerCustomerRatingType;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class EventAssistanceReviewService
{
    /**
     * @param  array{reviews?:array<int,array{workerId:int,rating:int,comment?:string|null}>}  $validated
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

        $participantWorkerIds = $this->participantWorkerIds($booking);
        if ($participantWorkerIds === []) {
            throw ValidationException::withMessages([
                'workers' => ['No completed event-team worker is available to receive this review.'],
            ]);
        }

        $reviewsByWorker = $this->normalizeReviews($validated['reviews'] ?? null, $participantWorkerIds);

        return DB::transaction(function () use ($booking, $reviewsByWorker): array {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CleaningBookingStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => ['The event can be reviewed only after all required event days are completed.'],
                ]);
            }

            $lockedParticipantWorkerIds = $this->participantWorkerIds($locked);
            $submittedWorkerIds = array_map('intval', array_keys($reviewsByWorker));
            sort($lockedParticipantWorkerIds);
            sort($submittedWorkerIds);

            if ($lockedParticipantWorkerIds !== $submittedWorkerIds) {
                throw ValidationException::withMessages([
                    'reviews' => ['The event team changed. Refresh the event and review every participating worker.'],
                ]);
            }

            if ($this->hasReview($locked)) {
                throw ValidationException::withMessages([
                    'review' => ['The event has already been reviewed.'],
                ]);
            }

            $reviews = [];
            foreach ($submittedWorkerIds as $workerId) {
                $review = $reviewsByWorker[$workerId];
                $reviews[] = WorkerCustomerRating::query()->create([
                    'booking_id' => $locked->id,
                    'booking_type' => $locked->getMorphClass(),
                    'worker_id' => $workerId,
                    'customer_id' => $locked->customer_id,
                    'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
                    'rating' => $review['rating'],
                    'comment' => $review['comment'],
                ]);
            }

            foreach ($submittedWorkerIds as $workerId) {
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

    /** @return array<int, int> */
    private function participantWorkerIds(CleaningBooking $booking): array
    {
        $workerIds = CleaningBookingSessionWorkerAssignment::query()
            ->whereHas('session', fn ($query) => $query
                ->where('cleaning_booking_id', $booking->id)
                ->where('status', CleaningBookingSessionStatus::Completed->value))
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->distinct()
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->filter(static fn (int $workerId): bool => $workerId > 0)
            ->values()
            ->all();

        if ($workerIds !== [] || $booking->sessions()->exists()) {
            return $workerIds;
        }

        // Backward compatibility for historical event bookings created before
        // execution sessions existed. Sessionized events never fall back here.
        return $booking->workerAssignments()
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->distinct()
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->filter(static fn (int $workerId): bool => $workerId > 0)
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $reviews
     * @param  array<int, int>  $participantWorkerIds
     * @return array<int,array{rating:int,comment:string|null}>
     */
    private function normalizeReviews(mixed $reviews, array $participantWorkerIds): array
    {
        if (! is_array($reviews) || $reviews === []) {
            throw ValidationException::withMessages([
                'reviews' => ['Submit one review for every unique worker who participated in the event.'],
            ]);
        }

        $participantSet = array_fill_keys($participantWorkerIds, true);
        $normalized = [];

        foreach ($reviews as $index => $review) {
            if (! is_array($review)) {
                throw ValidationException::withMessages([
                    "reviews.{$index}" => ['Each event worker review must be an object.'],
                ]);
            }

            $workerId = (int) ($review['workerId'] ?? 0);
            $rating = (int) ($review['rating'] ?? 0);
            $comment = isset($review['comment']) ? trim((string) $review['comment']) : null;

            if ($workerId <= 0 || ! isset($participantSet[$workerId])) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.workerId" => ['This worker did not participate in a completed event session.'],
                ]);
            }

            if (isset($normalized[$workerId])) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.workerId" => ['Each participating worker may be reviewed only once per event.'],
                ]);
            }

            if ($rating < 1 || $rating > 5) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.rating" => ['The rating must be between 1 and 5.'],
                ]);
            }

            if ($comment !== null && mb_strlen($comment) > 1000) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.comment" => ['The comment may not be greater than 1000 characters.'],
                ]);
            }

            $normalized[$workerId] = [
                'rating' => $rating,
                'comment' => $comment === '' ? null : $comment,
            ];
        }

        $submittedWorkerIds = array_map('intval', array_keys($normalized));
        sort($participantWorkerIds);
        sort($submittedWorkerIds);

        if ($participantWorkerIds !== $submittedWorkerIds) {
            throw ValidationException::withMessages([
                'reviews' => ['Submit exactly one review for every unique worker who participated in the event.'],
            ]);
        }

        return $normalized;
    }
}
