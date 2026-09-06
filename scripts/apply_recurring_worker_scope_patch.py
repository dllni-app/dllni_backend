from pathlib import Path


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, text: str) -> None:
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(text)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected 1 match, found {count}")
    return text.replace(old, new, 1)


# Shared request concern keeps legacy clients unchanged unless workerScope is explicit.
write(
    'Modules/User/app/Http/Requests/Concerns/ValidatesRecurringWorkerScope.php',
    '''<?php

declare(strict_types=1);

namespace Modules\\User\\Http\\Requests\\Concerns;

use Illuminate\\Validation\\Rule;
use Illuminate\\Validation\\Validator;

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
''',
)

# Migration: nullable columns preserve exact legacy inference for old clients/bookings.
write(
    'database/migrations/2026_09_06_000001_add_recurring_worker_scope_to_cleaning_bookings_table.php',
    '''<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->string('worker_scope', 32)->nullable()->after('assignment_mode');
            $table->json('specific_worker_ids')->nullable()->after('worker_scope');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn(['worker_scope', 'specific_worker_ids']);
        });
    }
};
''',
)

# Store request.
path = 'Modules/User/app/Http/Requests/UserCleaningOrderStoreRequest.php'
text = read(path)
text = replace_once(
    text,
    "use Modules\\User\\Http\\Requests\\Concerns\\ValidatesEventAssistanceSchedule;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesWorkerRoomAssignments;",
    "use Modules\\User\\Http\\Requests\\Concerns\\ValidatesEventAssistanceSchedule;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesRecurringWorkerScope;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesWorkerRoomAssignments;",
    'store request concern import',
)
text = replace_once(
    text,
    "    use ValidatesEventAssistanceSchedule;\n    use ValidatesWorkerRoomAssignments;",
    "    use ValidatesEventAssistanceSchedule;\n    use ValidatesRecurringWorkerScope;\n    use ValidatesWorkerRoomAssignments;",
    'store request concern use',
)
text = replace_once(
    text,
    "            'preferredWorkerId' => ['nullable', 'exists:workers,id'],\n            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],",
    "            'preferredWorkerId' => ['nullable', 'exists:workers,id'],\n            ...$this->recurringWorkerScopeRules(),\n            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],",
    'store request worker scope rule',
)
text = replace_once(
    text,
    "            $this->validateEventAssistanceSchedule($validator);\n\n            if ($this->requiresFemaleWorkerSafetyConfirmation()) {",
    "            $this->validateEventAssistanceSchedule($validator);\n            $this->validateRecurringWorkerScope($validator);\n\n            if ($this->requiresFemaleWorkerSafetyConfirmation()) {",
    'store request validation hook',
)
old = '''        if ($preferredWorkerIds !== [] || $this->has('preferredWorkerIds')) {
            $merge['preferredWorkerIds'] = $preferredWorkerIds;
            $merge['preferredWorkerId'] = $preferredWorkerIds[0] ?? null;

            if (count($preferredWorkerIds) > 1) {
                if (! $this->filled('numberOfWorkers')) {
                    $merge['numberOfWorkers'] = count($preferredWorkerIds);
                }

                if (! $this->filled('assignmentMode')) {
                    $merge['assignmentMode'] = 'open_count';
                }
            }
        }
'''
new = '''        $workerScopeMerge = $this->recurringWorkerScopeMerge($preferredWorkerIds);
        if ($workerScopeMerge !== []) {
            $merge = array_merge($merge, $workerScopeMerge);
        } elseif ($preferredWorkerIds !== [] || $this->has('preferredWorkerIds')) {
            // Legacy behavior is intentionally preserved when workerScope is absent.
            $merge['preferredWorkerIds'] = $preferredWorkerIds;
            $merge['preferredWorkerId'] = $preferredWorkerIds[0] ?? null;

            if (count($preferredWorkerIds) > 1) {
                if (! $this->filled('numberOfWorkers')) {
                    $merge['numberOfWorkers'] = count($preferredWorkerIds);
                }

                if (! $this->filled('assignmentMode')) {
                    $merge['assignmentMode'] = 'open_count';
                }
            }
        }
'''
text = replace_once(text, old, new, 'store request normalization')
write(path, text)

# Estimate request.
path = 'Modules/User/app/Http/Requests/UserCleaningOrderEstimatePriceRequest.php'
text = read(path)
text = replace_once(
    text,
    "use Modules\\User\\Http\\Requests\\Concerns\\ValidatesEventAssistanceSchedule;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesWorkerRoomAssignments;",
    "use Modules\\User\\Http\\Requests\\Concerns\\ValidatesEventAssistanceSchedule;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesRecurringWorkerScope;\nuse Modules\\User\\Http\\Requests\\Concerns\\ValidatesWorkerRoomAssignments;",
    'estimate request concern import',
)
text = replace_once(
    text,
    "    use ValidatesEventAssistanceSchedule;\n    use ValidatesWorkerRoomAssignments;",
    "    use ValidatesEventAssistanceSchedule;\n    use ValidatesRecurringWorkerScope;\n    use ValidatesWorkerRoomAssignments;",
    'estimate request concern use',
)
text = replace_once(
    text,
    "            'preferredWorkerId' => ['nullable', 'exists:workers,id'],\n            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],",
    "            'preferredWorkerId' => ['nullable', 'exists:workers,id'],\n            ...$this->recurringWorkerScopeRules(),\n            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],",
    'estimate request worker scope rule',
)
text = replace_once(
    text,
    "            $this->validateEventAssistanceSchedule($validator);\n            $this->validateWorkerRoomAssignments($validator);",
    "            $this->validateEventAssistanceSchedule($validator);\n            $this->validateRecurringWorkerScope($validator);\n            $this->validateWorkerRoomAssignments($validator);",
    'estimate request validation hook',
)
text = replace_once(text, old, new, 'estimate request normalization')
write(path, text)

# Booking model stores explicit scope while inferring legacy single preferred bookings.
path = 'Modules/Cleaning/app/Models/CleaningBooking.php'
text = read(path)
text = replace_once(
    text,
    "    public const PREFERRED_WORKER_REJECTION_DECISION_CANCELLED = 'cancelled';\n",
    "    public const PREFERRED_WORKER_REJECTION_DECISION_CANCELLED = 'cancelled';\n\n"
    "    public const WORKER_SCOPE_ANY = 'any';\n\n"
    "    public const WORKER_SCOPE_SPECIFIC = 'specific';\n",
    'booking scope constants',
)
text = replace_once(
    text,
    "        'assignment_mode',\n        'converted_from_preferred_worker',",
    "        'assignment_mode',\n        'worker_scope',\n        'specific_worker_ids',\n        'converted_from_preferred_worker',",
    'booking scope fillable',
)
text = replace_once(
    text,
    "            'assignment_mode' => CleaningAssignmentMode::class,\n            'converted_from_preferred_worker' => 'boolean',",
    "            'assignment_mode' => CleaningAssignmentMode::class,\n            'specific_worker_ids' => 'array',\n            'converted_from_preferred_worker' => 'boolean',",
    'booking scope cast',
)
marker = '''    public function requiresPreferredWorkerRejectionDecision(): bool
    {
'''
insert = '''    public function resolvedWorkerScope(): string
    {
        $explicit = mb_strtolower(mb_trim((string) ($this->worker_scope ?? '')));
        if (in_array($explicit, [self::WORKER_SCOPE_ANY, self::WORKER_SCOPE_SPECIFIC], true)) {
            return $explicit;
        }

        return $this->resolvedAssignmentMode() === CleaningAssignmentMode::PreferredWorker->value
            && $this->preferred_worker_id !== null
                ? self::WORKER_SCOPE_SPECIFIC
                : self::WORKER_SCOPE_ANY;
    }

    /** @return array<int, int> */
    public function specificWorkerIds(): array
    {
        $ids = [];
        foreach (is_array($this->specific_worker_ids) ? $this->specific_worker_ids : [] as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if (
            $ids === []
            && $this->resolvedWorkerScope() === self::WORKER_SCOPE_SPECIFIC
            && $this->preferred_worker_id !== null
        ) {
            $ids[] = (int) $this->preferred_worker_id;
        }

        return $ids;
    }

'''
text = replace_once(text, marker, insert + marker, 'booking scope helpers')
write(path, text)

# Persist explicit scope and validate every selected worker against the same coverage policy.
path = 'Modules/User/app/Services/UserCleaningOrderService.php'
text = read(path)
text = replace_once(
    text,
    "            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage($normalizedPropertyType, (array) $validated['propertyDetails']);\n            $resolvedAssignmentMode = $this->resolveAssignmentMode($validated);",
    "            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage($normalizedPropertyType, (array) $validated['propertyDetails']);\n"
    "            $explicitWorkerScope = $this->explicitWorkerScope($validated);\n"
    "            $specificWorkerIds = $this->normalizedSpecificWorkerIds($validated);\n"
    "            $resolvedAssignmentMode = $this->resolveAssignmentMode($validated);",
    'store service scope variables',
)
text = replace_once(
    text,
    "            if ($resolvedAssignmentMode === 'preferred_worker') {\n                $this->guardPreferredWorkerCoverage(\n                    $normalizedInput['preferredWorkerId'] !== null ? (int) $normalizedInput['preferredWorkerId'] : null,\n                    $resolvedNeighborhood,\n                );\n            }",
    "            if ($explicitWorkerScope === CleaningBooking::WORKER_SCOPE_SPECIFIC) {\n"
    "                $this->guardSpecificWorkerCoverage($specificWorkerIds, $resolvedNeighborhood);\n"
    "            } elseif ($resolvedAssignmentMode === 'preferred_worker') {\n"
    "                $this->guardPreferredWorkerCoverage(\n"
    "                    $normalizedInput['preferredWorkerId'] !== null ? (int) $normalizedInput['preferredWorkerId'] : null,\n"
    "                    $resolvedNeighborhood,\n"
    "                );\n"
    "            }",
    'store service scope coverage',
)
text = replace_once(
    text,
    "                'assignment_mode' => $resolvedAssignmentMode,\n                'number_of_workers' => $requestedWorkers,",
    "                'assignment_mode' => $resolvedAssignmentMode,\n"
    "                'worker_scope' => $explicitWorkerScope,\n"
    "                'specific_worker_ids' => $explicitWorkerScope === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                    ? $specificWorkerIds\n"
    "                    : null,\n"
    "                'number_of_workers' => $requestedWorkers,",
    'store service scope persistence',
)
text = replace_once(
    text,
    "    private function normalizedAssignmentMode(mixed $assignmentMode): ?string\n    {",
    '''    /** @param array<string, mixed> $validated */
    private function explicitWorkerScope(array $validated): ?string
    {
        if (! array_key_exists('workerScope', $validated) || ! is_string($validated['workerScope'])) {
            return null;
        }

        $scope = mb_strtolower(mb_trim($validated['workerScope']));

        return in_array($scope, [CleaningBooking::WORKER_SCOPE_ANY, CleaningBooking::WORKER_SCOPE_SPECIFIC], true)
            ? $scope
            : null;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, int>
     */
    private function normalizedSpecificWorkerIds(array $validated): array
    {
        $ids = [];
        foreach (is_array($validated['preferredWorkerIds'] ?? null) ? $validated['preferredWorkerIds'] : [] as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function normalizedAssignmentMode(mixed $assignmentMode): ?string
    {''',
    'store service scope helpers',
)
text = replace_once(
    text,
    "    private function guardPreferredWorkerCoverage(?int $preferredWorkerId, ?CleaningNeighborhood $neighborhood): void\n    {",
    '''    /** @param array<int, int> $specificWorkerIds */
    private function guardSpecificWorkerCoverage(array $specificWorkerIds, ?CleaningNeighborhood $neighborhood): void
    {
        if ($specificWorkerIds === [] || $neighborhood === null) {
            return;
        }

        $coveredIds = Worker::query()
            ->whereIn('id', $specificWorkerIds)
            ->coversNeighborhood((int) $neighborhood->id)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($specificWorkerIds, $coveredIds));
        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'preferredWorkerIds' => [self::PREFERRED_WORKER_NEIGHBORHOOD_MESSAGE],
        ]);
    }

    private function guardPreferredWorkerCoverage(?int $preferredWorkerId, ?CleaningNeighborhood $neighborhood): void
    {''',
    'specific worker coverage guard',
)
write(path, text)

# Estimate response surfaces canonical worker scope without altering legacy assignment semantics.
path = 'Modules/User/app/Http/Controllers/API/UserCleaningOrderEstimatePriceController.php'
text = read(path)
needle = '''            $requestedWorkers = $assignmentMode === 'preferred_worker'
                ? 1
                : max(
                    $selectedWorkerCount,
                    $hasExplicitWorkerCount || $preferredWorkerCount > 0
                        ? 1
                        : (int) ($estimation['recommendation']['suggestedTeamSize'] ?? 1),
                );
'''
replacement = needle + '''            $workerScope = $this->resolveWorkerScope($validated, $assignmentMode);
            $specificWorkerIds = $this->resolveSpecificWorkerIds($validated, $workerScope);
'''
text = replace_once(text, needle, replacement, 'estimate controller scope variables')
text = replace_once(
    text,
    "            'assignmentMode' => $assignmentMode,\n            ...$capacity,",
    "            'assignmentMode' => $assignmentMode,\n"
    "            'workerScope' => $workerScope,\n"
    "            'specificWorkerIds' => $specificWorkerIds,\n"
    "            ...$capacity,",
    'estimate response scope fields',
)
text = replace_once(
    text,
    "    /**\n     * @param  array<string, mixed>  $validated\n     * @return array<string, mixed>\n     */\n    private function workEnvironmentConfirmationPayload(array $validated): array",
    '''    /** @param array<string, mixed> $validated */
    private function resolveWorkerScope(array $validated, string $assignmentMode): string
    {
        $scope = is_string($validated['workerScope'] ?? null)
            ? mb_strtolower(mb_trim($validated['workerScope']))
            : null;

        if (in_array($scope, ['any', 'specific'], true)) {
            return $scope;
        }

        return $assignmentMode === 'preferred_worker' ? 'specific' : 'any';
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, int>
     */
    private function resolveSpecificWorkerIds(array $validated, string $workerScope): array
    {
        if ($workerScope !== 'specific') {
            return [];
        }

        $ids = [];
        foreach (is_array($validated['preferredWorkerIds'] ?? null) ? $validated['preferredWorkerIds'] : [] as $value) {
            if (is_numeric($value) && (int) $value > 0 && ! in_array((int) $value, $ids, true)) {
                $ids[] = (int) $value;
            }
        }

        if ($ids === [] && is_numeric($validated['preferredWorkerId'] ?? null)) {
            $ids[] = (int) $validated['preferredWorkerId'];
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function workEnvironmentConfirmationPayload(array $validated): array''',
    'estimate controller scope helpers',
)
write(path, text)

# Child-session acceptance is the final authoritative gate.
path = 'Modules/Cleaning/app/Services/CleaningBookingSessionWorkerEligibilityService.php'
text = read(path)
text = replace_once(
    text,
    "    ): array {\n        $worker->loadMissing(['user', 'deposit']);",
    "    ): array {\n"
    "        if (\n"
    "            (string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING\n"
    "            && $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "            && ! in_array((int) $worker->id, $booking->specificWorkerIds(), true)\n"
    "        ) {\n"
    "            return $this->blocked(\n"
    "                'worker_not_in_scope',\n"
    "                'This recurring booking is limited to workers selected by the customer.',\n"
    "            );\n"
    "        }\n\n"
    "        $worker->loadMissing(['user', 'deposit']);",
    'session eligibility scope gate',
)
write(path, text)

# Pending-order discovery cannot expose explicit multi-specific bookings to outsiders.
path = 'Modules/Cleaning/app/Traits/FilterQueries/CleaningBookingFilterQuery.php'
text = read(path)
text = replace_once(
    text,
    "                    $pending->where('status', CleaningBookingStatus::Pending)\n                        ->whereNull('worker_id')\n                        ->whereNull('preferred_worker_id')\n                        ->when(",
    "                    $pending->where('status', CleaningBookingStatus::Pending)\n"
    "                        ->whereNull('worker_id')\n"
    "                        ->whereNull('preferred_worker_id')\n"
    "                        ->where(function (Builder $scope) use ($worker): void {\n"
    "                            $scope->whereNull('worker_scope')\n"
    "                                ->orWhere('worker_scope', '!=', CleaningBooking::WORKER_SCOPE_SPECIFIC)\n"
    "                                ->orWhereJsonContains('specific_worker_ids', (int) $worker->id);\n"
    "                        })\n"
    "                        ->when(",
    'worker filter scope gate',
)
write(path, text)

# Explicit specific dispatch notifies only selected workers and never widens to the pool.
path = 'app/Jobs/NotifyEligibleWorkersNewOrderJob.php'
text = read(path)
text = replace_once(
    text,
    "        $acceptedWorkerIds = $booking->workerAssignments()\n            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())\n            ->pluck('worker_id')\n            ->map(static fn (mixed $workerId): int => (int) $workerId)\n            ->all();\n\n        if ($assignmentMode === CleaningAssignmentMode::PreferredWorker->value && $booking->preferred_worker_id !== null) {",
    "        $acceptedWorkerIds = $booking->workerAssignments()\n"
    "            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())\n"
    "            ->pluck('worker_id')\n"
    "            ->map(static fn (mixed $workerId): int => (int) $workerId)\n"
    "            ->all();\n\n"
    "        if ((string) ($booking->worker_scope ?? '') === CleaningBooking::WORKER_SCOPE_SPECIFIC) {\n"
    "            $this->dispatchToSpecificWorkers(\n"
    "                $booking,\n"
    "                $bookingDateTime,\n"
    "                $rejectedWorkerIds,\n"
    "                $acceptedWorkerIds,\n"
    "                $depositService,\n"
    "                $solvencyService,\n"
    "                $scheduleConflictService,\n"
    "            );\n\n"
    "            return;\n"
    "        }\n\n"
    "        if ($assignmentMode === CleaningAssignmentMode::PreferredWorker->value && $booking->preferred_worker_id !== null) {",
    'specific dispatch branch',
)
text = replace_once(
    text,
    "    private function isDispatchable(\n",
    '''    /**
     * @param array<int, int> $rejectedWorkerIds
     * @param array<int, int> $acceptedWorkerIds
     */
    private function dispatchToSpecificWorkers(
        CleaningBooking $booking,
        ?Carbon $bookingDateTime,
        array $rejectedWorkerIds,
        array $acceptedWorkerIds,
        DepositService $depositService,
        WorkerOrderSolvencyService $solvencyService,
        WorkerBookingScheduleConflictService $scheduleConflictService,
    ): void {
        $specificWorkerIds = array_values(array_diff(
            $booking->specificWorkerIds(),
            $rejectedWorkerIds,
            $acceptedWorkerIds,
        ));

        if ($specificWorkerIds === []) {
            $this->createDispatchAlert(
                $booking,
                'specific_workers_exhausted',
                'No customer-selected worker remains eligible for this recurring booking.',
                ['specificWorkerIds' => $booking->specificWorkerIds()],
            );

            return;
        }

        $workers = Worker::query()
            ->whereIn('id', $specificWorkerIds)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->when(
                $booking->gender_preference instanceof GenderPreference
                    && $booking->gender_preference !== GenderPreference::Any,
                fn ($query) => $query->where('gender', $booking->gender_preference->value),
            )
            ->when(
                $booking->neighborhood_id !== null,
                fn ($query) => $query->coversNeighborhood((int) $booking->neighborhood_id),
            )
            ->with(['user', 'deposit'])
            ->get();

        $notifiedCount = 0;
        $blockedWorkerIds = [];

        foreach ($workers as $worker) {
            if (! $this->isDispatchable($worker, $booking, $bookingDateTime, $depositService, $scheduleConflictService)) {
                $blockedWorkerIds[] = (int) $worker->id;

                continue;
            }

            $solvency = $solvencyService->solvencyPayloadForBooking($worker, $booking);
            if (! (bool) $solvency['canReceiveOrder']) {
                $blockedWorkerIds[] = (int) $worker->id;

                continue;
            }

            $this->notifyWorkerAboutNewOrder($worker, $booking);
            $notifiedCount++;
        }

        if ($notifiedCount === 0) {
            $this->createDispatchAlert(
                $booking,
                'specific_workers_unavailable',
                'None of the customer-selected workers is currently available and eligible for this recurring booking.',
                [
                    'specificWorkerIds' => $booking->specificWorkerIds(),
                    'blockedWorkerIds' => array_values(array_unique($blockedWorkerIds)),
                ],
            );
        }
    }

    private function isDispatchable(
''',
    'specific dispatch helper',
)
text = replace_once(
    text,
    "            'number_of_workers' => (int) ($booking->number_of_workers ?? 1),\n        ];",
    "            'number_of_workers' => (int) ($booking->number_of_workers ?? 1),\n"
    "            'workerScope' => $booking->resolvedWorkerScope(),\n"
    "            'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                ? $booking->specificWorkerIds()\n"
    "                : [],\n"
    "        ];",
    'dispatch broadcast scope payload',
)
write(path, text)

# Schedule API and session snapshots expose the authoritative scope.
path = 'Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php'
text = read(path)
text = replace_once(
    text,
    "            'isPaused' => $isRecurringPaused,\n            'canPause' => $canPauseRecurring,",
    "            'isPaused' => $isRecurringPaused,\n"
    "            'workerScope' => $booking->resolvedWorkerScope(),\n"
    "            'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                ? $booking->specificWorkerIds()\n"
    "                : [],\n"
    "            'canPause' => $canPauseRecurring,",
    'schedule presenter scope payload',
)
text = replace_once(
    text,
    "            'isRecurring' => false,\n            'isPaused' => false,\n            'canPause' => false,",
    "            'isRecurring' => false,\n"
    "            'isPaused' => false,\n"
    "            'workerScope' => $booking->resolvedWorkerScope(),\n"
    "            'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                ? $booking->specificWorkerIds()\n"
    "                : [],\n"
    "            'canPause' => false,",
    'schedule fallback scope payload',
)
write(path, text)

path = 'Modules/User/app/Services/RecurringCleaningScheduleService.php'
text = read(path)
text = replace_once(
    text,
    "                    'requiredWorkers' => max(1, (int) $booking->number_of_workers),\n                    'currency' => (string) config('app.currency', 'SYP'),",
    "                    'requiredWorkers' => max(1, (int) $booking->number_of_workers),\n"
    "                    'workerScope' => $booking->resolvedWorkerScope(),\n"
    "                    'specificWorkerIds' => $booking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                        ? $booking->specificWorkerIds()\n"
    "                        : [],\n"
    "                    'currency' => (string) config('app.currency', 'SYP'),",
    'recurring creation snapshot scope',
)
write(path, text)

path = 'Modules/User/app/Services/RecurringCleaningScheduleRevisionService.php'
text = read(path)
text = replace_once(
    text,
    "                        'requiredWorkers' => max(1, (int) $lockedBooking->number_of_workers),\n                        'currency' => (string) ($built['singleVisitPricing']['currency'] ?? config('app.currency', 'SYP')),",
    "                        'requiredWorkers' => max(1, (int) $lockedBooking->number_of_workers),\n"
    "                        'workerScope' => $lockedBooking->resolvedWorkerScope(),\n"
    "                        'specificWorkerIds' => $lockedBooking->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "                            ? $lockedBooking->specificWorkerIds()\n"
    "                            : [],\n"
    "                        'currency' => (string) ($built['singleVisitPricing']['currency'] ?? config('app.currency', 'SYP')),",
    'recurring revision snapshot scope',
)
write(path, text)

path = 'Modules/User/app/Http/Resources/UserCleaningBookingResource.php'
text = read(path)
text = replace_once(
    text,
    "        $payload['canEdit'] = $canEdit;\n        $payload['can_edit'] = $canEdit;",
    "        $payload['canEdit'] = $canEdit;\n"
    "        $payload['can_edit'] = $canEdit;\n"
    "        $payload['workerScope'] = $this->resolvedWorkerScope();\n"
    "        $payload['specificWorkerIds'] = $this->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC\n"
    "            ? $this->specificWorkerIds()\n"
    "            : [];",
    'user resource scope fields',
)
write(path, text)

# API-level recurring worker-scope tests are appended to the existing canonical recurring suite.
path = 'tests/Feature/UserModule/RecurringCleaningScheduleTest.php'
text = read(path)
text = replace_once(
    text,
    "use App\\Models\\CancellationPolicy;\nuse App\\Models\\User;",
    "use App\\Models\\CancellationPolicy;\nuse App\\Models\\User;\nuse App\\Models\\Worker;",
    'recurring test worker import',
)
text = replace_once(
    text,
    "use Modules\\Cleaning\\Enums\\CleaningBillingMode;\n",
    "use Modules\\Cleaning\\Enums\\CleaningAssignmentMode;\nuse Modules\\Cleaning\\Enums\\CleaningBillingMode;\n",
    'recurring test assignment import',
)
append = r'''

it('stores an explicit multi-worker recurring specific scope without widening the pool', function (): void {
    $workers = Worker::factory()->count(2)->create();
    $payload = recurringCleaningPayload();
    unset($payload['assignmentMode'], $payload['numberOfWorkers']);
    $payload['workerScope'] = 'specific';
    $payload['preferredWorkerIds'] = $workers->pluck('id')->all();

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();
    $estimate
        ->assertJsonPath('workerScope', 'specific')
        ->assertJsonPath('assignmentMode', CleaningAssignmentMode::OpenCount->value)
        ->assertJsonPath('workerAcceptance.required', 2);
    expect($estimate->json('specificWorkerIds'))->toBe($workers->pluck('id')->all());

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $create->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect((string) $booking->worker_scope)->toBe(CleaningBooking::WORKER_SCOPE_SPECIFIC)
        ->and($booking->specificWorkerIds())->toBe($workers->pluck('id')->all())
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value)
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and((int) $booking->number_of_workers)->toBe(2)
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (int) $session->required_workers === 2))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => data_get($session->pricing_snapshot, 'workerScope') === 'specific'))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => data_get($session->pricing_snapshot, 'specificWorkerIds') === $workers->pluck('id')->all()))->toBeTrue();

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.workerScope', 'specific')
        ->assertJsonPath('data.schedule.specificWorkerIds.0', (int) $workers[0]->id)
        ->assertJsonPath('data.schedule.specificWorkerIds.1', (int) $workers[1]->id);
});

it('canonicalizes explicit any-worker recurring scope and clears preferred ids', function (): void {
    $worker = Worker::factory()->create();
    $payload = recurringCleaningPayload();
    $payload['workerScope'] = 'any';
    $payload['preferredWorkerIds'] = [$worker->id];
    $payload['preferredWorkerId'] = $worker->id;
    $payload['assignmentMode'] = 'preferred_worker';

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $create->json('order.id'));

    expect((string) $booking->worker_scope)->toBe(CleaningBooking::WORKER_SCOPE_ANY)
        ->and($booking->specificWorkerIds())->toBe([])
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value);
});

it('rejects workerScope outside a recurring cleaning schedule', function (): void {
    $worker = Worker::factory()->create();
    $payload = recurringCleaningPayload();
    unset($payload['schedule']);
    $payload['workerScope'] = 'specific';
    $payload['preferredWorkerIds'] = [$worker->id];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workerScope');
});
'''
if "stores an explicit multi-worker recurring specific scope" in text:
    raise RuntimeError('recurring worker scope API tests already appended')
text = text.rstrip() + append + "\n"
write(path, text)

# Scope enforcement tests: acceptance gate, worker listing, and notifications.
write(
    'tests/Feature/Cleaning/RecurringCleaningWorkerScopeTest.php',
    r'''<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionWorkerEligibilityService;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    CleaningDepositSetting::query()->delete();
    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);
});

/** @return array{0:User,1:Worker} */
function makeRecurringScopeEligibleWorker(string $email): array
{
    $user = User::factory()->create(['email' => $email, 'is_active' => true]);
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 100,
        'default_working_hours' => $workingHours,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    return [$user, $worker];
}

/** @param array<int,int> $specificWorkerIds */
function makeRecurringSpecificScopeBooking(array $specificWorkerIds): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $scheduledAt = now(config('app.timezone'))->addDays(2)->setTime(10, 0);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'worker_scope' => CleaningBooking::WORKER_SCOPE_SPECIFIC,
        'specific_worker_ids' => $specificWorkerIds,
        'number_of_workers' => count($specificWorkerIds),
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'estimated_hours' => 2,
        'total_hours' => 4,
        'base_price' => 2000,
        'addons_total' => 0,
        'admin_margin_amount' => 200,
        'total_price' => 2200,
    ]);

    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => count($specificWorkerIds),
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
        'base_price' => 2000,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 200,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 2200,
        'is_pricing_final' => false,
    ]);

    return [$booking, $session];
}

it('blocks an outsider before recurring session acceptance eligibility checks', function (): void {
    [, $selectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-eligibility@example.com');
    [, $outsider] = makeRecurringScopeEligibleWorker('scope-outsider-eligibility@example.com');
    [$booking, $session] = makeRecurringSpecificScopeBooking([(int) $selectedWorker->id]);

    $result = app(CleaningBookingSessionWorkerEligibilityService::class)
        ->check($booking->fresh(), $session->fresh(), $outsider);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasonCode'])->toBe('worker_not_in_scope');
});

it('shows an explicit multi-specific pending booking only to selected workers', function (): void {
    [$selectedUser, $selectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-filter@example.com');
    [$otherSelectedUser, $otherSelectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-filter-2@example.com');
    [$outsiderUser] = makeRecurringScopeEligibleWorker('scope-outsider-filter@example.com');
    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $selectedWorker->id,
        (int) $otherSelectedWorker->id,
    ]);

    Sanctum::actingAs($selectedUser);
    $selectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($selectedIds)->toContain($booking->id);

    Sanctum::actingAs($otherSelectedUser);
    $otherSelectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($otherSelectedIds)->toContain($booking->id);

    Sanctum::actingAs($outsiderUser);
    $outsiderIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($outsiderIds)->not->toContain($booking->id);
});

it('dispatches explicit recurring specific scope only to selected workers', function (): void {
    Notification::fake();

    [$firstUser, $firstWorker] = makeRecurringScopeEligibleWorker('scope-selected-notify-1@example.com');
    [$secondUser, $secondWorker] = makeRecurringScopeEligibleWorker('scope-selected-notify-2@example.com');
    [$outsiderUser] = makeRecurringScopeEligibleWorker('scope-outsider-notify@example.com');
    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $firstWorker->id,
        (int) $secondWorker->id,
    ]);

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();

    Notification::assertSentTo($firstUser, NewOrderRequestNotification::class);
    Notification::assertSentTo($secondUser, NewOrderRequestNotification::class);
    Notification::assertNotSentTo($outsiderUser, NewOrderRequestNotification::class);
});
''',
)

print('recurring worker scope patch applied')
