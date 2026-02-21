<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOrderRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderFilterRequest extends FormRequest
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
            'filter.customerId' => 'sometimes|integer|exists:users,id',
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.status' => 'sometimes|string|in:pending,accepted,preparing,ready_for_pickup,completed,cancelled',
            'filter.pickupMode' => 'sometimes|string|in:immediate_pickup,scheduled_pickup',
            'filter.orderNumber' => 'sometimes|string|max:255',
            'filter.search' => 'sometimes|string|max:255',
            'filter.createdAfter' => 'sometimes|date',
            'filter.createdBefore' => 'sometimes|date|after_or_equal:filter.createdAfter',
            'sort' => 'sometimes|string|in:orderNumber,-orderNumber,totalAmount,-totalAmount,status,-status,createdAt,-createdAt',
        ];
    }
}
