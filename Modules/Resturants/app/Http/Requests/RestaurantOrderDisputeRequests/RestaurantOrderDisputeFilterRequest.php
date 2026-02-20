<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOrderDisputeRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantOrderDisputeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:open,under_review,resolved,closed',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:ticket_number,-ticket_number,status,-status,created_at,-created_at',
        ];
    }
}
