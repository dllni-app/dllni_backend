<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\CleaningTimeWarningRequests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningTimeWarningFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.bookingId' => 'sometimes|integer',
            'filter.bookingType' => 'sometimes|string|in:cleaning_booking,event_booking',
            'filter.sentAtFrom' => 'sometimes|date',
            'filter.sentAtTo' => 'sometimes|date|after_or_equal:filter.sentAtFrom',
            'filter.forCurrentWorker' => 'sometimes|boolean',
            'filter.pending' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:sentAt,-sentAt,createdAt,-createdAt',
        ];
    }
}
