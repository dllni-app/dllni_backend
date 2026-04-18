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
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'query' => ['sometimes', 'string', 'max:255'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'store_id' => ['sometimes', 'integer', 'exists:sm_stores,id'],
            'category_id' => ['sometimes', 'integer', 'exists:sm_categories,id'],
            'price_min' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'numeric', 'gte:price_min'],
            'is_available' => ['sometimes', 'boolean'],
            'filter.storeId' => ['sometimes', 'integer', 'exists:sm_stores,id'],
            'filter.categoryId' => ['sometimes', 'integer', 'exists:sm_categories,id'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.isAvailable' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:name,-name,price,-price,stockQuantity,-stockQuantity,expiresAt,-expiresAt,createdAt,-createdAt'],
        ];
    }
}
