<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;
use Modules\User\Models\UserAddress;
use Modules\User\Services\EventAssistanceScheduleService;
use Modules\User\Services\OpenTimeScheduleService;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Modules\User\Services\RecurringCleaningScheduleService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Support\CleaningWorkerCapacity;

final class UserCleaningOrderEstimatePriceController
{
    public function __invoke(
        UserCleaningOrderEstimatePriceRequest $request,
        UserCleaningOrderEstimationService $service,
        EventAssistanceScheduleService $eventSchedule,
        RecurringCleaningScheduleService $recurringSchedule,
        OpenTimeScheduleService $openTimeSchedule,
        CleaningExtendedTimePricingService $extendedTimePricing,
    ): JsonResponse {
        $validated = $request->validated();
        [$addressLatitude, $addressLongitude] = $this->resolveAddressCoordinates($validated, (int) $request->user()->id);
        $isEventAssistance = $service->isEventAssistanceType((string) $validated['propertyType']);
        $eventPlan = $isEventAssistance ? $eventSchedule->resolve($validated) : null;
        $openTimePlan = ! $isEventAssistance ? $openTimeSchedule->resolve($validated) : null;
        $recurringPlan = ! $isEventAssistance && $openTimePlan === null
            ? $recurringSchedule->resolve($validated)
            : null;

        try {
            $estimation = $service->estimate(
                (string) $validated['propertyType'],
                (array) $validated['propertyDetails'],
                isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
            );
            $singleVisitEstimatedHours = (float) $estimation['estimatedHours'];
            $recurringSessionHours = $recurringPlan !== null
                && (string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS
                    ? (float) ($recurringPlan['hoursPerVisit'] ?? $singleVisitEstimatedHours)
                    : $singleVisitEstimatedHours;

            if ($eventPlan !== null) {
                $estimation['estimatedHours'] = $eventPlan['totalHours'];
                if (is_array($estimation['recommendation'] ?? null)) {
                    $estimation['recommendation']['hours'] = $eventPlan['totalHours'];
                }
            } elseif ($recurringPlan !== null) {
                $estimation['estimatedHours'] = round(
                    $recurringSessionHours * (int) $recurringPlan['sessionsCount'],
                    2,
                );
            }

            $preferredWorkerCount = is_array($validated['preferredWorkerIds'] ?? null)
                ? count($validated['preferredWorkerIds'])
                : (isset($validated['preferredWorkerId']) ? 1 : 0);
            $hasExplicitWorkerCount = array_key_exists('numberOfWorkers', $validated)
                && is_numeric($validated['numberOfWorkers']);
            $explicitWorkerCount = max(1, (int) ($validated['numberOfWorkers'] ?? 1));
            $selectedWorkerCount = max(1, $explicitWorkerCount, $preferredWorkerCount);

            $assignmentMode = $this->resolveAssignmentMode(
                $validated['assignmentMode'] ?? null,
                $validated['preferredWorkerId'] ?? null,
                $selectedWorkerCount,
            );
            $requestedWorkers = $assignmentMode === 'preferred_worker'
                ? 1
                : max(
                    $selectedWorkerCount,
                    $hasExplicitWorkerCount || $preferredWorkerCount > 0
                        ? 1
                        : (int) ($estimation['recommendation']['suggestedTeamSize'] ?? 1),
                );
            $workerScope = $this->resolveWorkerScope($validated, $assignmentMode);
            $specificWorkerIds = $this->resolveSpecificWorkerIds($validated, $workerScope);

            $capacityHours = $openTimePlan !== null
                ? max(array_map(
                    static fn (array $session): float => (float) $session['expectedMaxMinutes'] / 60,
                    $openTimePlan['sessions'],
                ))
                : ($eventPlan !== null
                ? max(array_map(
                    static fn (array $session): float => (float) $session['hours'],
                    $eventPlan['sessions'],
                ))
                : ($recurringPlan !== null
                    ? $recurringSessionHours
                    : (float) $estimation['estimatedHours']));
            $capacity = CleaningWorkerCapacity::payload($capacityHours);

            $pricingPropertyDetails = (array) $validated['propertyDetails'];
            if ($isEventAssistance) {
                $pricingPropertyDetails['workerCount'] = $requestedWorkers;
            }

            if ($eventPlan !== null) {
                $pricing = $eventSchedule->quote(
                    plan: $eventPlan,
                    propertyType: (string) $validated['propertyType'],
                    propertyDetails: $pricingPropertyDetails,
                    addressLatitude: $addressLatitude,
                    addressLongitude: $addressLongitude,
                    preferredWorkerId: $assignmentMode === 'preferred_worker'
                        ? ($validated['preferredWorkerId'] ?? null)
                        : null,
                    requiredWorkers: $requestedWorkers,
                    requestMaterials: (bool) ($validated['requestMaterials'] ?? false),
                    specialServices: is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                );
            } else {
                $isOpenTime = is_array($validated['openTime'] ?? null);
                $pricing = $isOpenTime
                    ? $service->priceOpenTime(
                        (string) $validated['propertyType'],
                        $pricingPropertyDetails,
                        $addressLatitude,
                        $addressLongitude,
                        $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                        max(1, (int) ($validated['openTime']['workerCount'] ?? $requestedWorkers)),
                        max(15, (int) ($validated['openTime']['expectedMaxMinutes'] ?? 480)),
                    )
                    : ($recurringPlan !== null
                    && (string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS
                        ? $service->priceRecurringHours(
                            (string) $validated['propertyType'],
                            $pricingPropertyDetails,
                            $addressLatitude,
                            $addressLongitude,
                            $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                            $recurringSessionHours,
                            $requestedWorkers,
                            (bool) ($validated['requestMaterials'] ?? false),
                            is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                        )
                        : $service->price(
                            (string) $validated['propertyType'],
                            $pricingPropertyDetails,
                            $addressLatitude,
                            $addressLongitude,
                            $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                            isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
                            (bool) ($validated['requestMaterials'] ?? false),
                            is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                        ));

                if ($isOpenTime && $openTimePlan !== null) {
                    $pricing = $openTimeSchedule->quote($openTimePlan, $pricing);
                    $estimation['estimatedHours'] = round(((int) $openTimePlan['totalExpectedMinutes']) / 60, 2);
                }

                if ($recurringPlan !== null && ! $isOpenTime) {
                    $pricing = $recurringSchedule->quote(
                        $recurringPlan,
                        $pricing,
                        $recurringSessionHours,
                    );
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [$exception->getMessage()],
            ]);
        }

        $workerRoomAssignments = null;

        if (
            array_key_exists('workerRoomAssignments', $validated)
            && ! $isEventAssistance
        ) {
            $plan = WorkerRoomAssignmentPlanner::plan(
                (array) $validated['propertyDetails'],
                is_array($validated['workerRoomAssignments']) ? $validated['workerRoomAssignments'] : null,
                $assignmentMode,
                $requestedWorkers,
                isset($validated['preferredWorkerId']) ? (int) $validated['preferredWorkerId'] : null,
            );

            if ($plan['errors'] !== []) {
                throw ValidationException::withMessages($plan['errors']);
            }

            $roomPricingBase = round((float) $pricing['basePrice'] + (float) $pricing['addonsTotal'], 2);
            if ($recurringPlan !== null && is_array($pricing['schedule']['sessions'][0] ?? null)) {
                $firstSessionPricing = $pricing['schedule']['sessions'][0];
                $roomPricingBase = round(
                    (float) ($firstSessionPricing['basePrice'] ?? 0)
                    + (float) ($firstSessionPricing['addonsTotal'] ?? 0),
                    2,
                );
            }

            $workerRoomAssignments = WorkerRoomAssignmentPlanner::withPricingPreview(
                $plan['assignments'],
                $roomPricingBase,
            );
        }

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $pricing,
            'schedule' => ($eventPlan !== null || $recurringPlan !== null || $openTimePlan !== null) ? $pricing['schedule'] : null,
            'assignmentMode' => $assignmentMode,
            'workerScope' => $workerScope,
            'specificWorkerIds' => $specificWorkerIds,
            ...$capacity,
            'workerAcceptance' => [
                'required' => $requestedWorkers,
                'accepted' => 0,
                'remaining' => $requestedWorkers,
                'isFulfilled' => false,
            ],
            'recommendation' => $estimation['recommendation'] ?? null,
            'workerRoomAssignments' => $workerRoomAssignments,
            'workEnvironmentConfirmation' => $this->workEnvironmentConfirmationPayload($validated),
            'extendedTimeRanges' => $extendedTimePricing->ranges(),
            'algorithmVersion' => $service->algorithmVersion(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: mixed, 1: mixed}
     */
    private function resolveAddressCoordinates(array $validated, int $userId): array
    {
        $addressId = $validated['addressId'] ?? null;
        if (is_numeric($addressId)) {
            $address = UserAddress::query()
                ->whereKey((int) $addressId)
                ->where('user_id', $userId)
                ->first();

            if (! $address instanceof UserAddress) {
                throw ValidationException::withMessages([
                    'addressId' => ['Selected address is invalid.'],
                ]);
            }

            return [$address->latitude, $address->longitude];
        }

        return [
            $validated['addressLatitude'] ?? null,
            $validated['addressLongitude'] ?? null,
        ];
    }

    private function resolveAssignmentMode(mixed $assignmentMode, mixed $preferredWorkerId, mixed $numberOfWorkers): string
    {
        $normalizedMode = is_string($assignmentMode) && mb_trim($assignmentMode) !== ''
            ? mb_strtolower(mb_trim($assignmentMode))
            : null;
        $requestedWorkers = max(1, (int) ($numberOfWorkers ?? 1));
        $hasPreferredWorker = is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0;

        if ($normalizedMode === 'open_count') {
            return 'open_count';
        }

        if ($hasPreferredWorker && $requestedWorkers <= 1) {
            return 'preferred_worker';
        }

        if ($normalizedMode === 'preferred_worker') {
            return 'open_count';
        }

        if ($normalizedMode !== null) {
            return $normalizedMode;
        }

        return 'open_count';
    }

    /** @param array<string, mixed> $validated */
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
     * @param  array<string, mixed>  $validated
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
    private function workEnvironmentConfirmationPayload(array $validated): array
    {
        $genderPreference = mb_strtolower((string) ($validated['genderPreference'] ?? 'any'));

        if ($genderPreference !== 'female') {
            return [
                'required' => false,
            ];
        }

        return [
            'required' => true,
            ...app(FemaleWorkerSafetyPolicyService::class)->policyPayload(),
        ];
    }
}
