<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'commissionType' => 'sometimes|required|string|in:percentage,fixed',
            'value' => 'sometimes|required|numeric|min:0',
            'minOrderAmount' => 'nullable|numeric|min:0',
            'maxCommissionAmount' => 'nullable|numeric|min:0',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isDefault' => 'sometimes|boolean',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
