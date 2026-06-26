<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceController
{
    public function __invoke(
        UserCleaningOrderEstimatePriceRequest $request,
        UserCleaningOrderEstimationService $service,
        CleaningExtendedTimePricingService $extendedTimePricing,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $estimation = $service->estimate(
                (string) $validated['propertyType'],
                (array) $validated['propertyDetails'],
                isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
            );
            $pricing = $service->price(
                (string) $validated['propertyType'],
                (array) $validated['propertyDetails'],
                $validated['addressLatitude'] ?? null,
                $validated['addressLongitude'] ?? null,
                $validated['preferredWorkerId'] ?? null,
                isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [$exception->getMessage()],
            ]);
        }

        $assignmentMode = $this->resolveAssignmentMode(
            $validated['assignmentMode'] ?? null,
            $validated['preferredWorkerId'] ?? null,
            $validated['numberOfWorkers'] ?? null,
        );
        $requiredWorkers = $assignmentMode === 'preferred_worker'
            ? 1
            : max(1, (int) ($validated['numberOfWorkers'] ?? $estimation['recommendation']['suggestedTeamSize'] ?? 1));
        $workerRoomAssignments = null;

        if (
            array_key_exists('workerRoomAssignments', $validated)
            && ! $service->isEventAssistanceType((string) $validated['propertyType'])
        ) {
            $plan = WorkerRoomAssignmentPlanner::plan(
                (array) $validated['propertyDetails'],
                is_array($validated['workerRoomAssignments']) ? $validated['workerRoomAssignments'] : null,
                $assignmentMode,
                $requiredWorkers,
                isset($validated['preferredWorkerId']) ? (int) $validated['preferredWorkerId'] : null,
            );

            if ($plan['errors'] !== []) {
                throw ValidationException::withMessages($plan['errors']);
            }

            $workerRoomAssignments = WorkerRoomAssignmentPlanner::withPricingPreview(
                $plan['assignments'],
                round((float) $pricing['basePrice'] + (float) $pricing['addonsTotal'], 2),
            );
        }

        return response()->json([
            'size' => [
                'estimatedSqm' => $estimation['estimatedSqm'],
                'estimatedHours' => $estimation['estimatedHours'],
                'sizeTier' => $estimation['sizeTier'],
            ],
            'pricing' => $pricing,
            'assignmentMode' => $assignmentMode,
            'workerAcceptance' => [
                'required' => $requiredWorkers,
                'accepted' => 0,
                'remaining' => $requiredWorkers,
                'isFulfilled' => false,
            ],
            'recommendation' => $estimation['recommendation'] ?? null,
            'workerRoomAssignments' => $workerRoomAssignments,
            'workEnvironmentConfirmation' => $this->workEnvironmentConfirmationPayload($validated),
            'extendedTimeRanges' => $extendedTimePricing->ranges(),
            'algorithmVersion' => $service->algorithmVersion(),
        ]);
    }

    private function resolveAssignmentMode(mixed $assignmentMode, mixed $preferredWorkerId, mixed $numberOfWorkers): string
    {
        if (is_string($assignmentMode) && mb_trim($assignmentMode) !== '') {
            return mb_strtolower(mb_trim($assignmentMode));
        }

        if (is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0 && ((int) ($numberOfWorkers ?? 1)) <= 1) {
            return 'preferred_worker';
        }

        return 'open_count';
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
