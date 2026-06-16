<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\User\Services\UserCleaningOrderEstimationService;

trait ValidatesWorkerRoomAssignments
{
    /**
     * @return array<string, mixed>
     */
    protected function workerRoomAssignmentRules(): array
    {
        return [
            'workerRoomAssignments' => ['nullable', 'array'],
            'workerRoomAssignments.*.workerSlot' => ['required_with:workerRoomAssignments', 'integer', 'min:1'],
            'workerRoomAssignments.*.preferredWorkerId' => ['nullable', 'integer', 'exists:workers,id'],
            'workerRoomAssignments.*.rooms' => ['required_with:workerRoomAssignments', 'array'],
            'workerRoomAssignments.*.rooms.*.roomKey' => ['required', 'string'],
            'workerRoomAssignments.*.rooms.*.roomType' => ['required', 'string'],
            'workerRoomAssignments.*.rooms.*.roomSize' => ['required', 'string'],
        ];
    }

    protected function validateWorkerRoomAssignments(Validator $validator): void
    {
        if (! $this->has('workerRoomAssignments')) {
            return;
        }

        if ($this->isEventAssistanceForWorkerRoomAssignments()) {
            return;
        }

        $numberOfWorkers = $this->resolvedWorkerRoomAssignmentCount();
        $assignmentMode = $this->resolvedWorkerRoomAssignmentMode();
        $preferredWorkerId = $this->input('preferredWorkerId');

        $plan = WorkerRoomAssignmentPlanner::plan(
            is_array($this->input('propertyDetails')) ? $this->input('propertyDetails') : [],
            is_array($this->input('workerRoomAssignments')) ? $this->input('workerRoomAssignments') : null,
            $assignmentMode,
            $numberOfWorkers,
            is_numeric($preferredWorkerId) ? (int) $preferredWorkerId : null,
        );

        foreach ($plan['errors'] as $path => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($path, $message);
            }
        }
    }

    private function resolvedWorkerRoomAssignmentMode(): string
    {
        $assignmentMode = $this->input('assignmentMode');

        if (is_string($assignmentMode) && mb_trim($assignmentMode) !== '') {
            return mb_strtolower(mb_trim($assignmentMode));
        }

        $preferredWorkerId = $this->input('preferredWorkerId');
        $numberOfWorkers = (int) ($this->input('numberOfWorkers') ?? 1);

        if (is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0 && $numberOfWorkers <= 1) {
            return 'preferred_worker';
        }

        return 'open_count';
    }

    private function resolvedWorkerRoomAssignmentCount(): int
    {
        $count = $this->input('numberOfWorkers');

        if (is_numeric($count) && (int) $count > 0) {
            return (int) $count;
        }

        return $this->resolvedWorkerRoomAssignmentMode() === 'preferred_worker' ? 1 : 1;
    }

    private function isEventAssistanceForWorkerRoomAssignments(): bool
    {
        return mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }
}
