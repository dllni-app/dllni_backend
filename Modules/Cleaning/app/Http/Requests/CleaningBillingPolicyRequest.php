<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBillingPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'billingMode' => 'required|string|in:full_booked_time,actual_working_time',
            'rules' => 'nullable|array',
            'isActive' => 'nullable|boolean',
            'isDefault' => 'nullable|boolean',
        ];
    }
}
