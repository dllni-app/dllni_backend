<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderUpdateRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;
use Modules\User\Services\EventAssistanceScheduleService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderUpdateController
{
    public function __invoke(
        UserCleaningOrderUpdateRequest $request,
        int $order,
        UserCleaningOrderService $service,
        EventAssistanceScheduleService $eventSchedule,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $validated = $this->withEventWorkerCount($request->validated(), $model);
        $updated = DB::transaction(function () use ($model, $validated, $service, $eventSchedule): CleaningBooking {
            $schedule = $validated['schedule'] ?? null;
            unset($validated['schedule']);

            if (is_array($schedule)) {
                $eventSchedule->assertEditable($model);
            }

            $updated = $service->update($model, $validated);

            if (is_array($schedule)) {
                $updated = $eventSchedule->replaceSchedule($updated, ['schedule' => $schedule]);
            }

            return $updated;
        });
        $updated->load([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
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
