<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantReputationLogRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantReputationLogFilterRequest extends FormRequest
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
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:score_delta,-score_delta,created_at,-created_at',
        ];
    }
}
