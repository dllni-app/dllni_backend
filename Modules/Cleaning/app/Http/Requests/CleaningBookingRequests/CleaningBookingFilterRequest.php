<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\CleaningBookingRequests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBookingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => 'sometimes|string|in:pending,confirmed,worker_assigned,worker_on_the_way,worker_arrived,in_progress,completed,cancelled',
            'filter.scheduledDateFrom' => 'sometimes|date',
            'filter.scheduledDateTo' => 'sometimes|date|after_or_equal:filter.scheduledDateFrom',
            'filter.customerId' => 'sometimes|exists:users,id',
            'filter.workerId' => 'sometimes|exists:workers,id',
            'filter.hasDispute' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:scheduled_date,-scheduled_date,created_at,-created_at,status,-status,total_price,-total_price',
        ];
    }
}
