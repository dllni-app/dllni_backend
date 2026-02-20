<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantId = $this->route('restaurant')?->id;

        return [
            'userId' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:restaurants,slug,'.$restaurantId,
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
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
            'suspensionUntil' => 'nullable|date',
        ];
    }
}
