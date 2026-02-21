<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmCartItemRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCartItemFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.cartId' => 'sometimes|integer|exists:sm_carts,id',
            'filter.productId' => 'sometimes|integer|exists:sm_products,id',
            'sort' => 'sometimes|string|in:quantity,-quantity,createdAt,-createdAt',
        ];
    }
}
