<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter.search' => ['required', 'string', 'min:1', 'max:255', 'regex:/.*\S.*/'],
            'filter.categoryId' => 'sometimes|integer|exists:categories,id',
            'filter.isFeatured' => 'sometimes|boolean',
            'filter.lowStock' => 'sometimes|boolean',
            'filter.masterProductId' => 'sometimes|integer|exists:master_products,id',
            'filter.minPrice' => 'sometimes|numeric|min:0',
            'filter.maxPrice' => 'sometimes|numeric|min:0|gte:filter.minPrice',
            'filter.hasDiscount' => 'sometimes|boolean',
            'perPage' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
            'sort' => 'sometimes|string|in:name,-name,price,-price,createdAt,-createdAt',
        ];
    }
}
