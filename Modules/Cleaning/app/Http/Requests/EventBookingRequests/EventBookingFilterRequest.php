<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\EventBookingRequests;

use Illuminate\Foundation\Http\FormRequest;

final class EventBookingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:pending,confirmed,team_assigned,in_progress,completed,cancelled',
            'filter.eventType' => 'sometimes|string|in:family_dinner,birthday,large_gathering,funeral,other',
            'filter.scheduledDateFrom' => 'sometimes|date',
            'filter.scheduledDateTo' => 'sometimes|date|after_or_equal:filter.scheduledDateFrom',
            'sort' => 'sometimes|string|in:scheduled_date,-scheduled_date,created_at,-created_at,status,-status,total_price,-total_price',
        ];
    }
}
