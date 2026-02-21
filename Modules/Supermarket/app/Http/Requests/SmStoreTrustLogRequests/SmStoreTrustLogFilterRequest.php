<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmStoreTrustLogRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreTrustLogFilterRequest extends FormRequest
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
            'filter.eventType' => 'sometimes|string|max:255',
            'filter.triggeredByUserId' => 'sometimes|integer|exists:users,id',
            'filter.referenceType' => 'sometimes|string|max:255',
            'filter.referenceId' => 'sometimes|integer',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:scoreDelta,-scoreDelta,createdAt,-createdAt',
        ];
    }
}
