<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'averageRating' => (float) $this->average_rating,
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
            'zones' => $this->whenLoaded('zones'),
            'availability' => $this->whenLoaded('availability'),
            'trustLogs' => $this->whenLoaded('trustLogs'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
