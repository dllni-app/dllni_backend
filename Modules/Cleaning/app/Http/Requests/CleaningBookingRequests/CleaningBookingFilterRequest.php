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
            'filter.status' => 'sometimes|string|in:pending,worker_assigned,in_progress,completed,cancelled',
            'filter.scheduledDateFrom' => 'sometimes|date',
            'filter.scheduledDateTo' => 'sometimes|date|after_or_equal:filter.scheduledDateFrom',
            'filter.scheduledDate' => 'sometimes|date',
            'filter.customerId' => 'sometimes|exists:users,id',
            'filter.workerId' => 'sometimes|exists:workers,id',
            'filter.forCurrentWorker' => 'sometimes|boolean',
            'filter.hasDispute' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:scheduledDate,-scheduledDate,createdAt,-createdAt,status,-status,totalPrice,-totalPrice',
        ];
    }
}
