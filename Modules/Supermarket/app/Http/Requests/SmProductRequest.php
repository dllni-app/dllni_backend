<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'categoryId' => 'sometimes|required|integer|exists:sm_categories,id',
            'masterProductId' => 'nullable|integer|exists:master_products,id',
            'name' => 'sometimes|required|string|max:255',
            'barcode' => 'nullable|string|max:255',
            'sourceType' => 'sometimes|required|string|in:barcode_scan,catalog_search,manual,template,bulk_import',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'discountedPrice' => 'nullable|numeric|min:0|lte:price',
            'stockQuantity' => 'sometimes|integer|min:0',
            'lowStockThreshold' => 'sometimes|integer|min:0',
            'expiresAt' => 'nullable|date',
            'isAvailable' => 'sometimes|boolean',
            'image' => 'sometimes|nullable|image|max:5120',
        ];
    }
}
