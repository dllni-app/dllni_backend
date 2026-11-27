<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;
        $restaurantId = app(\Modules\Resturants\Support\RestaurantOwnerContext::class)->restaurantId();

        return [
            'restaurantId' => ['sometimes', 'integer', \Illuminate\Validation\Rule::in([$restaurantId])],
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('categories', 'slug')->where('restaurant_id', $restaurantId)->ignore($categoryId),
            ],
            'sortOrder' => 'nullable|integer|min:0',
            'parentId' => 'sometimes|nullable|integer|min:1',
            'isActive' => 'sometimes|boolean',
            'categoryImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ];
    }
}
