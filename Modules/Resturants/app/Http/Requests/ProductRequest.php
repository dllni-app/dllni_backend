<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'prohibited',
            'categoryId' => 'required|exists:categories,id',
            'itemType' => 'nullable|string|in:meal,sandwich,burger,pizza,dish,combo,family_meal,appetizer,side,salad,dessert,drink,breakfast,other',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'searchTags' => 'nullable|array|max:20',
            'searchTags.*' => 'string|max:80',
            'price' => 'required|numeric|min:0',
            'discountedPrice' => 'nullable|numeric|min:0',
            'isAvailable' => 'nullable|boolean',
            'stockQuantity' => 'nullable|integer|min:0',
            'lowStockThreshold' => 'nullable|integer|min:0',
            'preparationTime' => 'nullable|integer|min:0',
            'isFeatured' => 'nullable|boolean',
            'primaryImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ];
    }
}
