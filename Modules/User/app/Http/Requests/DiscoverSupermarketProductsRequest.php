<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DiscoverSupermarketProductsRequest extends FormRequest
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'filter.storeId' => ['sometimes', 'integer', 'exists:sm_stores,id'],
            'filter.categoryId' => ['sometimes', 'integer', 'exists:sm_categories,id'],
            'sort' => ['sometimes', 'string', 'in:name,-name,price,-price,stockQuantity,-stockQuantity,expiresAt,-expiresAt,createdAt,-createdAt'],
        ];
    }
}
