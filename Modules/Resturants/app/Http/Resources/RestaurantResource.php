<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Restaurant;

/**
 * @mixin Restaurant
 */
final class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'district' => $this->district,
            'locationDetails' => $this->location_details,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            'phone' => $this->phone,
            'whatsappNumber' => $this->whatsapp_number,
            'email' => $this->email,
            'instagramUsername' => $this->instagram_username,
            'facebookPageName' => $this->facebook_page_name,
            'averageRating' => $this->average_rating ? (float) $this->average_rating : null,
            'totalReviews' => $this->total_reviews,
            'estimatedPreparationTime' => $this->estimated_preparation_time,
            'minimumOrderAmount' => $this->minimum_order_amount ? (float) $this->minimum_order_amount : null,
            'priceRange' => $this->price_range?->value ?? $this->price_range,
            'reputationScore' => $this->reputation_score,
            'warningCount' => $this->warning_count,
            'visibilityScore' => $this->visibility_score,
            'manualVisibilityOverride' => $this->manual_visibility_override,
            'isActive' => $this->is_active,
            'isFeatured' => $this->is_featured,
            'isTemporarilyClosed' => $this->is_temporarily_closed,
            'suspensionUntil' => $this->suspension_until?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'cuisineTypes' => $this->whenLoaded('cuisineTypes', fn () => $this->cuisineTypes->map(fn ($ct) => [
                'id' => $ct->id,
                'name' => $ct->name,
                'slug' => $ct->slug,
            ])),
            'operatingHours' => $this->whenLoaded('operatingHours'),
            'documents' => $this->whenLoaded('documents'),
            'reputationLogs' => $this->whenLoaded('reputationLogs'),
            'penalties' => $this->whenLoaded('penalties'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
