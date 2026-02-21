<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmProductRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmProductFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
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
