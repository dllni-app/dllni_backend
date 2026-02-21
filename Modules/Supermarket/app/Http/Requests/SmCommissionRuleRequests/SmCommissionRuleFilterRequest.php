<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmCommissionRuleRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCommissionRuleFilterRequest extends FormRequest
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
            'filter.commissionType' => 'sometimes|string|max:255',
            'filter.isDefault' => 'sometimes|boolean',
            'filter.isActive' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:value,-value,startsAt,-startsAt,endsAt,-endsAt,createdAt,-createdAt',
        ];
    }
}
