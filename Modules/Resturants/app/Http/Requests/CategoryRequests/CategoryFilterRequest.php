<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\CategoryRequests;

use Illuminate\Foundation\Http\FormRequest;

final class CategoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,sort_order,-sort_order,created_at,-created_at',
        ];
    }
}
