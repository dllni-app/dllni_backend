<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderCompletionExtendTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'additionalMinutes' => ['required', 'integer', 'min:0', 'max:90'],
            'message' => ['nullable', 'string', 'max:1000'],
            'workerId' => ['nullable', 'integer', 'exists:workers,id'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'assignmentId' => ['nullable', 'integer', 'exists:cleaning_booking_worker_assignments,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:cleaning_booking_worker_assignments,id'],
        ];
    }

    public function customerMessage(): ?string
    {
        $message = $this->validated('message');

        if (! is_string($message)) {
            return null;
        }

        $message = mb_trim($message);

        return $message !== '' ? $message : null;
    }

    public function targetWorkerId(): ?int
    {
        $workerId = $this->validated('workerId') ?? $this->validated('worker_id');

        return is_numeric($workerId) ? (int) $workerId : null;
    }

    public function targetAssignmentId(): ?int
    {
        $assignmentId = $this->validated('assignmentId') ?? $this->validated('assignment_id');

        return is_numeric($assignmentId) ? (int) $assignmentId : null;
    }
}
