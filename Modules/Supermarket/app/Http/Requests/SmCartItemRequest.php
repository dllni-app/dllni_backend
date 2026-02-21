<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cartId' => 'sometimes|required|integer|exists:sm_carts,id',
            'productId' => 'sometimes|required|integer|exists:sm_products,id',
            'quantity' => 'sometimes|required|integer|min:1',
            'unitPrice' => 'sometimes|required|numeric|min:0',
        ];
    }
}
