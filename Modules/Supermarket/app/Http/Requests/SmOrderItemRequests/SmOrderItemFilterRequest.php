<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOrderItemRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderItemFilterRequest extends FormRequest
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
            'filter.productId' => 'sometimes|integer|exists:sm_products,id',
            'filter.productName' => 'sometimes|string|max:255',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:quantity,-quantity,totalPrice,-totalPrice,createdAt,-createdAt',
        ];
    }
}
