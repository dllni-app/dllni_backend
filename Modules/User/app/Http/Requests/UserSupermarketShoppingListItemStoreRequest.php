<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserSupermarketShoppingListItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'masterProductId' => ['required', 'integer', 'exists:master_products,id'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'isIncluded' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('sortOrder')) {
            $this->merge(['sortOrder' => 0]);
        }
        if (! $this->has('isIncluded')) {
            $this->merge(['isIncluded' => true]);
        }
    }
}
