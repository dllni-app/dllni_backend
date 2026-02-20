<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\CleaningBillingPolicyRequests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBillingPolicyFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.isActive' => 'sometimes|boolean',
            'filter.isDefault' => 'sometimes|boolean',
            'filter.billingMode' => 'sometimes|string|in:full_booked_time,actual_working_time',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,billingMode,-billingMode,createdAt,-createdAt',
        ];
    }
}
