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

        return [
            'userId' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('sm_carts', 'user_id')->ignore($cartId),
            ],
        ];
    }
}
