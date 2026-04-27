<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmSmartListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => 'sometimes|required|integer|exists:users,id',
            'storeId' => 'sometimes|nullable|integer|exists:sm_stores,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'isActive' => 'sometimes|boolean',
            'schedule' => 'sometimes|nullable|array',
            'schedule.isActive' => 'sometimes|boolean',
            'schedule.frequencyType' => 'required_with:schedule|string|in:weekly,monthly,once',
            'schedule.weekDays' => 'required_if:schedule.frequencyType,weekly|array|min:1',
            'schedule.weekDays.*' => 'integer|min:0|max:6',
            'schedule.monthDays' => 'required_if:schedule.frequencyType,monthly|array|min:1',
            'schedule.monthDays.*' => 'integer|min:1|max:31',
            'schedule.periods' => 'required_with:schedule|array|min:1',
            'schedule.periods.*.label' => 'nullable|string|max:255',
            'schedule.periods.*.fromTime' => 'required|string|date_format:H:i',
            'schedule.periods.*.toTime' => 'required|string|date_format:H:i',
        ];
    }
}
