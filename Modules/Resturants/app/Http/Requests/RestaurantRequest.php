<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Resturants\Models\Restaurant;

final class RestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantRoute = $this->route('restaurant');
        $restaurantId = null;

        if ($restaurantRoute instanceof Restaurant) {
            $restaurantId = $restaurantRoute->id;
        } elseif (is_numeric($restaurantRoute)) {
            $restaurantId = (int) $restaurantRoute;
        } else {
            $owner = auth()->user();
            if ($owner) {
                /** @var Restaurant|null $ownedRestaurant */
                $ownedRestaurant = $owner->restaurants()->first();
                $restaurantId = $ownedRestaurant?->id;
            }
        }

        return [
            'userId' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:restaurants,slug,'.$restaurantId,
            'description' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'locationDetails' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'whatsappNumber' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'instagramUsername' => 'nullable|string|max:100',
            'facebookPageName' => 'nullable|string|max:100',
            'averageRating' => 'nullable|numeric|min:0|max:5',
            'totalReviews' => 'nullable|integer|min:0',
            'estimatedPreparationTime' => 'nullable|integer|min:0',
            'minimumOrderAmount' => 'nullable|numeric|min:0',
            'priceRange' => 'nullable|string|in:low,medium,high,premium',
            'reputationScore' => 'nullable|integer|min:0|max:100',
            'warningCount' => 'nullable|integer|min:0',
            'visibilityScore' => 'nullable|integer|min:0',
            'manualVisibilityOverride' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
            'isFeatured' => 'nullable|boolean',
            'isTemporarilyClosed' => 'nullable|boolean',
            'suspensionUntil' => 'nullable|date',
        ];
    }
}
