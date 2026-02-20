<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\OrderRequests;

use Illuminate\Foundation\Http\FormRequest;

final class OrderFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:pending,accepted,preparing,ready_for_pickup,picked_up,completed,cancelled',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.orderType' => 'sometimes|string|in:pickup,dine_in',
            'filter.pickupMode' => 'sometimes|string|in:immediate_pickup,scheduled_pickup',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'filter.hasDispute' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:order_number,-order_number,status,-status,total_amount,-total_amount,created_at,-created_at',
        ];
    }
}
