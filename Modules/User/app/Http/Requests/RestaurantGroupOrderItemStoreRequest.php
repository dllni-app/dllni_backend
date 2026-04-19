<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantGroupOrderItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productId' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'modifierIds' => ['sometimes', 'array', 'max:30'],
            'modifierIds.*' => ['integer', 'exists:modifiers,id'],
            'substituteProductId' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'specialInstructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
