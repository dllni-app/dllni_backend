<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'dayOfWeek' => 'sometimes|required|integer|min:0|max:6',
            'opensAt' => 'nullable|string',
            'closesAt' => 'nullable|string',
            'isClosed' => 'sometimes|boolean',
        ];
    }
}
