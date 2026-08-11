<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'minimumLimit' => 'required|numeric|min:0',
            'unitCost' => 'nullable|numeric|min:0',
            // Backward-compatible field used by older clients. When `products`
            // is provided, its quantityUsed values take precedence.
            'productIds' => 'nullable|array',
            'productIds.*' => 'integer|distinct|exists:products,id',
            'products' => 'nullable|array',
            'products.*.productId' => 'required|integer|distinct|exists:products,id',
            'products.*.quantityUsed' => 'required|numeric|gt:0',
        ];
    }
}
