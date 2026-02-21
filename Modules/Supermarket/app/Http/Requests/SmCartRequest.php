<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cartId = $this->route('sm_cart');
        $userId = $this->input('userId');
        $storeId = $this->input('storeId');

        return [
            'userId' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('sm_carts', 'user_id')
                    ->where(fn ($query) => $query->where('store_id', $storeId))
                    ->ignore($cartId),
            ],
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
        ];
    }
}
