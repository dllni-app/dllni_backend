<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\ProductRequests;

use Illuminate\Foundation\Http\FormRequest;

final class ProductFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'prohibited',
            'filter.categoryId' => 'sometimes|exists:categories,id',
            'filter.isAvailable' => 'sometimes|boolean',
            'filter.lowStock' => 'sometimes|boolean',
            'filter.isFeatured' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,price,-price,created_at,-created_at',
        ];
    }
}
