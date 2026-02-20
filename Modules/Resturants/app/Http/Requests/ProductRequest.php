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
        $productId = $this->route('product')?->id;
        $restaurantId = $this->input('restaurantId') ?? $this->route('product')?->restaurant_id;

        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'categoryId' => 'required|exists:categories,id',
            'masterProductId' => 'nullable|exists:master_products,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('products', 'slug')->where('restaurant_id', $restaurantId)->ignore($productId),
            ],
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discountedPrice' => 'nullable|numeric|min:0',
            'isAvailable' => 'nullable|boolean',
            'stockQuantity' => 'nullable|integer|min:0',
            'lowStockThreshold' => 'nullable|integer|min:0',
            'preparationTime' => 'nullable|integer|min:0',
            'isFeatured' => 'nullable|boolean',
        ];
    }
}
