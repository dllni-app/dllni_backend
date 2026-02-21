<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmInventoryLogRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmInventoryLogFilterRequest extends FormRequest
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
            'filter.productId' => 'sometimes|integer|exists:sm_products,id',
            'filter.type' => 'sometimes|string|max:255',
            'filter.userId' => 'sometimes|integer|exists:users,id',
            'filter.referenceType' => 'sometimes|string|max:255',
            'filter.referenceId' => 'sometimes|integer',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:quantityChange,-quantityChange,createdAt,-createdAt',
        ];
    }
}
