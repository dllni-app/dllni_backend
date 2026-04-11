<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserSupermarketShoppingListItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:9999'],
            'sortOrder' => ['sometimes', 'required', 'integer', 'min:0', 'max:999999'],
            'isIncluded' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
