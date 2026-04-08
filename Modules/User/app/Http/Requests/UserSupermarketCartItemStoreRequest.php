<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserSupermarketCartItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchantId' => ['required', 'integer', 'exists:sm_stores,id'],
            'productId' => ['required', 'integer', 'exists:sm_products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:200'],
            'modifierIds' => ['sometimes', 'array'],
            'substituteProductId' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
