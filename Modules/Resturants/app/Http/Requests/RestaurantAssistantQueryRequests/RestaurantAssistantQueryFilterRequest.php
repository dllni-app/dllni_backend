<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantAssistantQueryRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantAssistantQueryFilterRequest extends FormRequest
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
            'filter.userId' => 'sometimes|exists:users,id',
            'filter.inputMode' => 'sometimes|string|in:text,voice',
            'filter.hasRecipeMatch' => 'sometimes|boolean',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:created_at,-created_at',
        ];
    }
}
