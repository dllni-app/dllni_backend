<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerOrderItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => 'sometimes|integer|min:1|max:100',
            'substituteProductId' => 'sometimes|nullable|exists:products,id',
            'specialInstructions' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
