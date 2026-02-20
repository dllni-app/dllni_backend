<?php

declare(strict_types=1);

namespace App\Http\Requests\SosAlertRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SosAlertFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:triggered,acknowledged,resolved',
            'filter.emergencyType' => 'sometimes|string|in:safety_threat,medical_emergency,severe_conflict',
            'sort' => 'sometimes|string|in:triggeredAt,-triggeredAt,createdAt,-createdAt',
        ];
    }
}
