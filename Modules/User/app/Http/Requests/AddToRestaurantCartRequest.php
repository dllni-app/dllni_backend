<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddToRestaurantCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'productId' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'modifierIds' => ['sometimes', 'array', 'max:30'],
            'modifierIds.*' => ['integer', 'exists:modifiers,id'],
            'substituteProductId' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'specialInstructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
