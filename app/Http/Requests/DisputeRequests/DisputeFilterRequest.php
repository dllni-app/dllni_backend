<?php

declare(strict_types=1);

namespace App\Http\Requests\DisputeRequests;

use Illuminate\Foundation\Http\FormRequest;

final class DisputeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:open,under_review,resolved,closed',
            'filter.category' => 'sometimes|string|in:poor_quality,property_damage,unprofessional,billing_issue,other',
            'filter.bookingType' => 'sometimes|string|in:cleaning_booking,event_booking',
            'sort' => 'sometimes|string|in:createdAt,-createdAt,status,-status',
        ];
    }
}
