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
        $restaurantId = $this->input('restaurantId') ?? $this->route('category')?->restaurant_id;

        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('categories', 'slug')->where('restaurant_id', $restaurantId)->ignore($categoryId),
            ],
            'sortOrder' => 'nullable|integer|min:0',
        ];
    }
}
