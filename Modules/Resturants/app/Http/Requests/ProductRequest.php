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

    protected function prepareForValidation(): void
    {
        if ($this->has('ingredientExclusions') && !is_array($this->input('ingredientExclusions'))) {
            $input = $this->input('ingredientExclusions');
            if (is_string($input) || $input === null) {
                $this->merge(['ingredientExclusions' => array_values(array_filter(array_map('trim', preg_split('/[,،\r\n]+/u', (string) $input) ?: [])))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'prohibited',
            'categoryId' => 'required|exists:categories,id',
            'itemType' => 'nullable|string|in:meal,sandwich,burger,pizza,dish,combo,family_meal,appetizer,side,salad,dessert,drink,breakfast,other',
            'productKind' => 'sometimes|nullable|string|in:main_dish,combo_meal,side_dish,beverage,dessert,standalone_sauce,other',
            'primaryFoodCategoryId' => 'sometimes|nullable|integer|exists:food_discovery_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'searchTags' => 'nullable|array|max:20',
            'searchTags.*' => 'string|max:80',
            'servings' => 'sometimes|nullable|integer|min:0|max:50',
            'ingredientExclusions' => 'sometimes|nullable|array|max:20',
            'ingredientExclusions.*' => 'string|max:80',
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
