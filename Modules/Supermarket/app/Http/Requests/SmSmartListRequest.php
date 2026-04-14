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
            'schedule.dayOfWeek' => 'nullable|integer|min:0|max:6|required_if:schedule.frequencyType,weekly',
            'schedule.dayOfMonth' => 'nullable|integer|min:1|max:31|required_if:schedule.frequencyType,monthly',
            'schedule.runDate' => 'nullable|date|required_if:schedule.frequencyType,once',
        ];
    }
}
