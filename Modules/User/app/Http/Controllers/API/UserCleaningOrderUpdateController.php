<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingScheduleService;
use Modules\Cleaning\Services\CleaningBookingSessionPricingService;
use Modules\User\Http\Requests\UserCleaningOrderUpdateRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderUpdateController
{
    public function __invoke(
        UserCleaningOrderUpdateRequest $request,
        int $order,
        UserCleaningOrderService $service,
        CleaningBookingScheduleService $scheduleService,
        CleaningBookingSessionPricingService $sessionPricing,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $validated = $this->withEventWorkerCount($request->validated(), $model);
        $scheduleInput = $request->input('schedule');
        $scheduleChanged = is_array($scheduleInput)
            || $request->has('scheduledDate')
            || $request->has('scheduledTime')
            || $request->has('propertyDetails.hours');

        $updated = DB::transaction(function () use ($service, $model, $validated, $scheduleInput, $scheduleChanged, $scheduleService, $sessionPricing): CleaningBooking {
            $updated = $service->update($model, $validated);

            if ($updated->isEventAssistanceBooking() && $scheduleChanged) {
                $updated = $scheduleService->sync($updated, array_merge($validated, [
                    'schedule' => is_array($scheduleInput) ? $scheduleInput : null,
                ]));
                $updated = $sessionPricing->initializeFromParent($updated);
            }

            return $updated;
        });

        $updated->load([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return response()->json([
            'order' => UserCleaningBookingResource::make($updated),
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function withEventWorkerCount(array $validated, CleaningBooking $booking): array
    {
        $propertyType = mb_strtolower((string) ($validated['propertyType'] ?? $booking->property_type));
        if ($propertyType !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return $validated;
        }

        $propertyDetails = (array) ($validated['propertyDetails'] ?? []);

        if (array_key_exists('numberOfWorkers', $validated) && is_numeric($validated['numberOfWorkers'])) {
            $workerCount = max(1, (int) $validated['numberOfWorkers']);
        } else {
            $assignmentMode = mb_strtolower((string) ($validated['assignmentMode'] ?? $booking->resolvedAssignmentMode()));
            $preferredWorkerId = array_key_exists('preferredWorkerId', $validated)
                ? $validated['preferredWorkerId']
                : $booking->preferred_worker_id;

            $workerCount = $assignmentMode === 'preferred_worker'
                || ($assignmentMode !== 'open_count' && is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0)
                    ? 1
                    : max(1, (int) ($booking->number_of_workers ?? 1));
        }

        $propertyDetails['workerCount'] = $workerCount;
        $validated['propertyDetails'] = $propertyDetails;

        return $validated;
    }
}
