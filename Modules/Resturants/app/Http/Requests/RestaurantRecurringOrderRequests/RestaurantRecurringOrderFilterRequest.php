<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantRecurringOrderRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantRecurringOrderFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:active,paused,cancelled',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:status,-status,next_run_at,-next_run_at,created_at,-created_at',
        ];
    }
}
