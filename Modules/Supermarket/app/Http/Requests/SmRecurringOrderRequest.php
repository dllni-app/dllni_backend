<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmRecurringOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => 'sometimes|required|integer|exists:users,id',
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'status' => 'sometimes|required|string|in:active,paused,cancelled',
            'frequency' => 'sometimes|required|string|max:255',
            'frequencyConfig' => 'nullable|array',
            'nextRunAt' => 'nullable|date',
            'lastRunAt' => 'nullable|date',
            'pausedAt' => 'nullable|date',
        ];
    }
}
