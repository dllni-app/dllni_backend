<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BookingReview;
use App\Models\Worker;
use App\Models\WorkerZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * @mixin Worker
 */
final class WorkerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'firstName' => $this->first_name,
            'gender' => $this->gender,
            'birthday' => $this->birthday?->toDateString(),
            'preferred_work_type' => $this->preferred_work_type?->value ?? $this->preferred_work_type ?? 'both',
            'avatar' => $this->when(
                $this->relationLoaded('media') && $this->getFirstMedia('avatar'),
                fn () => MediaResource::make($this->getFirstMedia('avatar'))
            ),
            'bio' => $this->bio,
            'averageRating' => $this->resolveCleaningAverageRating(),
            'totalCompletedJobs' => $this->total_completed_jobs,
            'trustScore' => $this->trust_score,
            'acceptanceRate' => (float) $this->acceptance_rate,
            'cancellationRate' => (float) $this->cancellation_rate,
            'openDisputesCount' => $this->open_disputes_count,
            'isActive' => $this->is_active,
            'isSuspended' => $this->is_suspended,
            'suspendedUntil' => $this->suspended_until?->toDateTimeString(),
            'homeAddress' => $this->home_address,
            'homeLatitude' => $this->home_latitude !== null ? (float) $this->home_latitude : null,
            'homeLongitude' => $this->home_longitude !== null ? (float) $this->home_longitude : null,
            'defaultWorkingHours' => $this->resource->getNormalizedDefaultWorkingHours(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
            'zones' => $this->whenLoaded('zones', fn () => $this->zones
                ->map(fn (WorkerZone $zone): array => $this->serializeZone($zone))
                ->values()
                ->all()),
            'availability' => $this->whenLoaded('availability'),
            'trustLogs' => $this->whenLoaded('trustLogs'),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function resolveCleaningAverageRating(): float
    {
        $average = BookingReview::query()
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $bookingQuery): void {
                $bookingQuery->where(function (Builder $workerScope): void {
                    $workerScope->where('worker_id', $this->id)
                        ->orWhereHas('workerAssignments', function (Builder $assignmentQuery): void {
                            $assignmentQuery
                                ->where('worker_id', $this->id)
                                ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                        });
                });
            })
            ->avg('rating');

        if ($average !== null) {
            return round((float) $average, 1);
        }

        return (float) $this->average_rating;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeZone(WorkerZone $zone): array
    {
        $neighborhood = $zone->relationLoaded('neighborhood') ? $zone->neighborhood : null;

        return [
            'id' => $zone->id,
            'neighborhoodId' => $zone->neighborhood_id !== null ? (int) $zone->neighborhood_id : null,
            'name' => $zone->name,
            'nameAr' => $neighborhood?->name_ar,
            'nameEn' => $neighborhood?->name_en,
            'cityName' => $neighborhood?->city_name,
            'isActive' => (bool) $zone->is_active,
        ];
    }
}
