<?php

declare(strict_types=1);

namespace App\Http\Requests\SystemAlertRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SystemAlertFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:new,acknowledged,resolved',
            'filter.alertType' => 'sometimes|string|in:delayed_rating,frozen_gps,sos_triggered,time_expired,overdue_completion,anomaly_detected',
            'filter.severity' => 'sometimes|string|in:low,medium,high,critical',
            'sort' => 'sometimes|string|in:createdAt,-createdAt,status,-status',
        ];
    }
}
