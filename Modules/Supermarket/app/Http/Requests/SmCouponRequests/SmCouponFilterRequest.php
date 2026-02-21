<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmCouponRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCouponFilterRequest extends FormRequest
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
            'filter.code' => 'sometimes|string|max:255',
            'filter.isActive' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:code,-code,startsAt,-startsAt,endsAt,-endsAt,createdAt,-createdAt',
        ];
    }
}
