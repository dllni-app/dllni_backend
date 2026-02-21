<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmStoreHoursRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreHoursFilterRequest extends FormRequest
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
            'filter.dayOfWeek' => 'sometimes|integer|min:0|max:6',
            'filter.isClosed' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:dayOfWeek,-dayOfWeek,createdAt,-createdAt',
        ];
    }
}
