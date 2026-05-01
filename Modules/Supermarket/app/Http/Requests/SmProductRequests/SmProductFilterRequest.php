<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmProductRequests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmProductFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $storeId = app(StoreOwnerContextService::class)->ownedStore()->id;
        $filter = $this->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }
        $filter['storeId'] = $storeId;

        $this->merge([
            'filter' => $filter,
            'store_id' => $storeId,
        ]);
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:255',
            'query' => 'sometimes|string|max:255',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'store_id' => 'sometimes|integer|exists:sm_stores,id',
            'category_id' => 'sometimes|integer|exists:sm_categories,id',
            'price_min' => 'sometimes|numeric|min:0',
            'price_max' => 'sometimes|numeric|gte:price_min',
            'is_available' => 'sometimes|boolean',
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.categoryId' => 'sometimes|integer|exists:sm_categories,id',
            'filter.barcode' => 'sometimes|string|max:255',
            'filter.sourceType' => 'sometimes|string|in:BarcodeScan,CatalogSearch,Manual,Template,BulkImport',
            'filter.isAvailable' => 'sometimes|boolean',
            'filter.lowStock' => 'sometimes|boolean',
            'filter.expiringSoon' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,price,-price,stockQuantity,-stockQuantity,expiresAt,-expiresAt,createdAt,-createdAt',
        ];
    }
}
