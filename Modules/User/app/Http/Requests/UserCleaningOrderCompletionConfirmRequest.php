<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderCompletionConfirmRequest extends FormRequest
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
            'workerId' => ['nullable', 'integer', 'exists:workers,id'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'assignmentId' => ['nullable', 'integer', 'exists:cleaning_booking_worker_assignments,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:cleaning_booking_worker_assignments,id'],
        ];
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
