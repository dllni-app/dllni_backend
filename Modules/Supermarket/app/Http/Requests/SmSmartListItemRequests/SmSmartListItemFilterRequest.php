<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmSmartListItemRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmSmartListItemFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.smartListId' => 'sometimes|integer|exists:sm_smart_lists,id',
            'filter.masterProductId' => 'sometimes|integer|exists:master_products,id',
            'sort' => 'sometimes|string|in:sortOrder,-sortOrder,createdAt,-createdAt',
        ];
    }
}
