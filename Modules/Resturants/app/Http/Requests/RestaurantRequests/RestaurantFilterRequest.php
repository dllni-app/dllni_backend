<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.isActive' => 'sometimes|boolean',
            'filter.isFeatured' => 'sometimes|boolean',
            'filter.isSuspended' => 'sometimes|boolean',
            'filter.cuisineType' => 'sometimes|exists:cuisine_types,id',
            'filter.priceRange' => 'sometimes|string|in:low,medium,high,premium',
            'filter.reputationScoreMin' => 'sometimes|integer|min:0|max:100',
            'filter.reputationScoreMax' => 'sometimes|integer|min:0|max:100|gte:filter.reputationScoreMin',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,slug,-slug,reputation_score,-reputation_score,created_at,-created_at',
        ];
    }
}
