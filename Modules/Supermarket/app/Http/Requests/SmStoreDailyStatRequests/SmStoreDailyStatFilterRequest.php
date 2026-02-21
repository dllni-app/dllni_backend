<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmStoreDailyStatRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreDailyStatFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:date,-date,createdAt,-createdAt',
        ];
    }
}
