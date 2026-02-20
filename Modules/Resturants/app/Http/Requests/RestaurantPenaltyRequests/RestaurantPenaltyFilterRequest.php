<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantPenaltyRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantPenaltyFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.type' => 'sometimes|string|in:warning,fine,suspension',
            'sort' => 'sometimes|string|in:penalty_type,-penalty_type,created_at,-created_at',
        ];
    }
}
