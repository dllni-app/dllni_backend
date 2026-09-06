<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesRecurringWorkerScope
{
    private const RECURRING_WORKER_SCOPE_ANY = 'any';

    private const RECURRING_WORKER_SCOPE_SPECIFIC = 'specific';

    /** @return array<string, array<int, mixed>> */
    protected function recurringWorkerScopeRules(): array
    {
        return [
            'workerScope' => ['nullable', 'string', Rule::in([
                self::RECURRING_WORKER_SCOPE_ANY,
                self::RECURRING_WORKER_SCOPE_SPECIFIC,
            ])],
        ];
    }

    /**
     * @param  array<int, int>  $preferredWorkerIds
     * @return array<string, mixed>
     */
    protected function recurringWorkerScopeMerge(array $preferredWorkerIds): array
    {
        if (! $this->filled('workerScope')) {
            return [];
        }

        $scope = mb_strtolower(mb_trim((string) $this->input('workerScope')));

        if ($scope === self::RECURRING_WORKER_SCOPE_ANY) {
            return [
                'workerScope' => self::RECURRING_WORKER_SCOPE_ANY,
                'preferredWorkerIds' => [],
                'preferredWorkerId' => null,
                'assignmentMode' => 'open_count',
            ];
        }

        if ($scope !== self::RECURRING_WORKER_SCOPE_SPECIFIC) {
            return ['workerScope' => $scope];
        }

        $merge = [
            'workerScope' => self::RECURRING_WORKER_SCOPE_SPECIFIC,
            'preferredWorkerIds' => $preferredWorkerIds,
            'preferredWorkerId' => $preferredWorkerIds[0] ?? null,
        ];

        if ($preferredWorkerIds !== []) {
            $merge['numberOfWorkers'] = count($preferredWorkerIds);
            $merge['assignmentMode'] = count($preferredWorkerIds) === 1
                ? 'preferred_worker'
                : 'open_count';
        }

        return $merge;
    }

    protected function validateRecurringWorkerScope(Validator $validator): void
    {
        if (! $this->filled('workerScope')) {
            return;
        }

        $scope = mb_strtolower(mb_trim((string) $this->input('workerScope')));
        $schedule = $this->input('schedule');
        $isRecurring = is_array($schedule)
            && mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) === 'recurring';

        if (! $isRecurring) {
            $validator->errors()->add(
                'workerScope',
                'Worker scope can only be selected for a recurring cleaning booking.',
            );

            return;
        }

        if ($scope !== self::RECURRING_WORKER_SCOPE_SPECIFIC) {
            return;
        }

        $preferredWorkerIds = $this->input('preferredWorkerIds');
        if (! is_array($preferredWorkerIds) || $preferredWorkerIds === []) {
            $validator->errors()->add(
                'preferredWorkerIds',
                'Select at least one worker when recurring worker scope is specific.',
            );
        }
    }
}
