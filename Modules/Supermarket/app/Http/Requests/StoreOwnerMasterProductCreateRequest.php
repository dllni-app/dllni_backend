<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOwnerMasterProductCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => ['required', 'integer', 'exists:sm_stores,id'],
            'categoryId' => ['required', 'integer', 'exists:sm_categories,id'],
            'masterProductId' => ['required', 'integer', 'exists:master_products,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'stockQuantity' => ['required', 'integer', 'min:0'],
            'lowStockThreshold' => ['sometimes', 'integer', 'min:0'],
            'discountedPrice' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'description' => ['nullable', 'string'],
            'expiresAt' => ['nullable', 'date'],
            'isAvailable' => ['sometimes', 'boolean'],
        ];
    }
}
