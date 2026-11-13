<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRestaurantPromotionClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:0'],
            'productId' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'modifierIds' => ['sometimes', 'array', 'max:30'],
            'modifierIds.*' => ['integer', 'distinct', 'min:1'],
            'substituteProductId' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'convertItemId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
