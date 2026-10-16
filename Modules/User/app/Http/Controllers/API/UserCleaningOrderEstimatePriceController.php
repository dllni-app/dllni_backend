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
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Modules\User\Services\OpenTimeScheduleService;
use Modules\User\Services\RecurringCleaningScheduleService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\RoomPeriodPricingService;
use Modules\User\Services\RoomPeriodBookingCreationService;
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
        RoomPeriodPricingService $roomPeriodPricing,
        RoomPeriodBookingCreationService $periodCreation,
    ): JsonResponse {
        $validated = $request->validated();
        if ((int) ($validated['contractVersion'] ?? 0) === 2) {
            $result = app(\Modules\User\Services\CleaningTimePeriodQuoteService::class)->estimate($request->user(), $validated);
            return response()->json($result);
        }
        if (is_array($validated['roomPeriods'] ?? null)) {
            try {
                $quote = $roomPeriodPricing->quote($validated);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['roomPeriods' => [$exception->getMessage()]]);
            }

            return response()->json([
                'size' => [
                    'estimatedHours' => round($quote['estimatedWorkMinutes'] / 60, 2),
                    'estimatedWorkMinutes' => $quote['estimatedWorkMinutes'],
                    'estimatedDurationMinutes' => $quote['estimatedDurationMinutes'],
                ],
                'pricing' => [
                    // Customer sees exactly one aggregate price, not hidden
                    // transportation, labor, materials or commission lines.
                    'totalPrice' => $quote['totalPrice'],
                    'currency' => $quote['currency'],
                    'isPricingFinal' => $quote['isPricingFinal'],
                ],
                'roomPeriods' => array_map(static fn (array $period): array => [
                    'clientKey' => $period['clientKey'],
                    'visitSequence' => $period['visitSequence'],
                    'scheduledDate' => $period['scheduledDate'],
                    'scheduledTime' => $period['scheduledTime'],
                    'estimatedEndAt' => $period['estimatedEndAt'],
                    'numberOfWorkers' => $period['numberOfWorkers'],
                    'estimatedWorkMinutes' => $period['estimatedWorkMinutes'],
                    'estimatedDurationMinutes' => $period['estimatedDurationMinutes'],
                ], $quote['roomPeriods']),
                'requiredSimultaneousWorkers' => $quote['requiredSimultaneousWorkers'],
                'pricingAlgorithm' => $quote['algorithmVersion'],
                'quoteSignature' => $periodCreation->signature($quote, $validated, (int) $request->user()->id),
                'materialsPreview' => app(\Modules\Cleaning\Services\CleaningMaterialQuoteService::class)
                    ->quote((array) $validated['propertyDetails'], null, false)['lines'],
            ]);
        }
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
                            $requestedWorkers,
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

        $clientPricing = $this->legacyMinimumPriceCompatibility($pricing);
        $clientPricing['priceSummary'] = $this->priceSummary($pricing, $openTimePlan !== null);

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'estimatedWorkHours' => $isEventAssistance || $openTimePlan !== null ? null : ($estimation['estimatedWorkHours'] ?? $singleVisitEstimatedHours),
                'estimatedDurationMinutes' => $isEventAssistance || $openTimePlan !== null
                    ? null
                    : max(1, (int) ceil(((float) ($estimation['estimatedRawMinutes'] ?? ($singleVisitEstimatedHours * 60))) / max(1, $requestedWorkers))),
                'estimatedDurationHours' => $isEventAssistance || $openTimePlan !== null
                    ? null
                    : round(ceil(((float) ($estimation['estimatedRawMinutes'] ?? ($singleVisitEstimatedHours * 60))) / max(1, $requestedWorkers)) / 60, 2),
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $clientPricing,
            'materialsPreview' => $isEventAssistance || $openTimePlan !== null
                ? []
                : app(\Modules\Cleaning\Services\CleaningMaterialQuoteService::class)
                    ->quote($pricingPropertyDetails, null, false)['lines'],
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

    /** @param array<string, mixed> $pricing */
    private function priceSummary(array $pricing, bool $isMaximum): array
    {
        $total = (float) ($pricing['totalPrice'] ?? 0);
        $transport = (float) ($pricing['travelFee'] ?? 0);
        $materials = (float) ($pricing['materialsTotal'] ?? 0);
        $specialServices = (float) ($pricing['specialServicesTotal'] ?? 0);
        $discount = max(0.0, (float) ($pricing['discountAmount'] ?? 0));

        return [
            'quoteKind' => $isMaximum ? 'preliminary' : 'estimate',
            'serviceCharge' => round($total + $discount - $transport - $materials - $specialServices, 2),
            'transport' => $transport,
            'materials' => $materials,
            'specialServices' => $specialServices,
            'discount' => $discount,
            'total' => $total,
            'currency' => $pricing['currency'] ?? config('app.currency', 'SYP'),
            'isPricingFinal' => (bool) ($pricing['isPricingFinal'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: mixed, 1: mixed}
     */
    /**
     * Temporary compatibility layer for the currently published Flutter app.
     *
     * The released app renders "service value" as basePrice + adminMargin.
     * When the configured minimum already includes the administration margin,
     * returning the real margin makes the app add it a second time. Keep the
     * real backend pricing untouched and reshape only the user-facing estimate
     * payload until the fixed app version is released.
     *
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private function legacyMinimumPriceCompatibility(array $pricing): array
    {
        if (! (bool) ($pricing['pricingAlgorithm']['minimumOrderApplied'] ?? false)) {
            return $pricing;
        }

        $actualAdminMargin = max(0.0, (float) ($pricing['adminMargin'] ?? 0));
        $basePrice = max(0.0, (float) ($pricing['basePrice'] ?? 0));
        $travelFee = max(0.0, (float) ($pricing['travelFee'] ?? 0));
        $totalPrice = max(0.0, (float) ($pricing['totalPrice'] ?? 0));
        $customerServicePrice = max(0.0, round($totalPrice - $travelFee, 2));

        $pricing['actualAdminMargin'] = $actualAdminMargin;
        $pricing['adminMargin'] = max(0.0, round($customerServicePrice - $basePrice, 2));
        $pricing['legacyMinimumPriceCompatibility'] = true;

        return $pricing;
    }

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
