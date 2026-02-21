<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmRecurringOrderRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmRecurringOrderFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.userId' => 'sometimes|integer|exists:users,id',
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.status' => 'sometimes|string|in:active,paused,cancelled',
            'filter.frequency' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:nextRunAt,-nextRunAt,createdAt,-createdAt',
        ];
    }
}
