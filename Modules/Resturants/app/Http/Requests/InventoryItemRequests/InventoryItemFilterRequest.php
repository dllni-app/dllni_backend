<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\InventoryItemRequests;

use Illuminate\Foundation\Http\FormRequest;

final class InventoryItemFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.search' => 'sometimes|string|max:255',
            'filter.status' => 'sometimes|string|in:normal,low',
            'filter.lowStock' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:name,-name,quantity,-quantity,unitCost,-unitCost,createdAt,-createdAt',
        ];
    }
}
