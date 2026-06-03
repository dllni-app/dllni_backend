<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderRoomAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.roomId' => ['required', 'integer', 'distinct', 'exists:cleaning_booking_rooms,id'],
            'assignments.*.workerId' => ['nullable', 'integer', 'exists:workers,id'],
        ];
    }
}
