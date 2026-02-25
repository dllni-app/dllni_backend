<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Supermarket\Enums\RejectionType;

final class SmOrderRejectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:500',
            'rejectionType' => ['required', 'string', 'in:' . implode(',', array_map(fn($case) => $case->value, RejectionType::cases()))],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Rejection reason is required.',
            'reason.min' => 'Reason must be at least 10 characters.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
            'rejectionType.required' => 'Rejection type is required.',
            'rejectionType.in' => 'Invalid rejection type. Must be one of: out_of_stock, fake_order, other.',
        ];
    }
}
