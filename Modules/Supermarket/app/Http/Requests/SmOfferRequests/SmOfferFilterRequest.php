<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOfferRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOfferFilterRequest extends FormRequest
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
            'filter.isActive' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,startsAt,-startsAt,endsAt,-endsAt,createdAt,-createdAt',
        ];
    }
}
