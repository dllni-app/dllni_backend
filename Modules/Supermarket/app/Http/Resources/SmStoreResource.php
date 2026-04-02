<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmStore;

/**
 * @mixin SmStore
 */
final class SmStoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ownerUserId' => $this->owner_user_id,
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone' => $this->phone,
            'email' => $this->email,
            'cover' => $this->cover,
            'logo' => $this->logo,
            'averageRating' => $this->average_rating,
            'totalReviews' => $this->total_reviews,
            'trustScore' => $this->trust_score,
            'warningCount' => $this->warning_count,
            'isActive' => $this->is_active,
            'isFeatured' => $this->is_featured,
            'suspensionUntil' => $this->suspension_until?->toDateTimeString(),
            'distanceKm' => array_key_exists('distanceKm', $this->getAttributes())
                ? round((float) $this->distanceKm, 2)
                : null,
            'storeHours' => SmStoreHoursResource::collection($this->whenLoaded('storeHours')),
            'documents' => SmStoreDocumentResource::collection($this->whenLoaded('documents')),
            'trustLogs' => SmStoreTrustLogResource::collection($this->whenLoaded('trustLogs')),
            'dailyStats' => SmStoreDailyStatResource::collection($this->whenLoaded('dailyStats')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
