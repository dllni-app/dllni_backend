<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerOrderItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productId' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:100',
            'substituteProductId' => 'nullable|exists:products,id',
            'specialInstructions' => 'nullable|string|max:1000',
        ];
    }
}
