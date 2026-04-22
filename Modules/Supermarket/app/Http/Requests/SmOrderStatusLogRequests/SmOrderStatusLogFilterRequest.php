<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOrderStatusLogRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderStatusLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'filter.orderId' => 'sometimes|integer|exists:sm_orders,id',
            'filter.changedByUserId' => 'sometimes|integer|exists:users,id',
            'filter.fromStatus' => 'sometimes|string|in:pending,accepted,preparing,ready_for_pickup,picked_up,completed,cancelled',
            'filter.toStatus' => 'sometimes|string|in:pending,accepted,preparing,ready_for_pickup,picked_up,completed,cancelled',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:createdAt,-createdAt',
        ];
    }
}
