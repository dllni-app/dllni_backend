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
use Modules\User\Services\UserCleaningMultiDayEventPricingService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderUpdateController
{
    public function __invoke(
        UserCleaningOrderUpdateRequest $request,
        int $order,
        UserCleaningOrderService $service,
        UserCleaningMultiDayEventPricingService $multiDayPricing,
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
        $eventPricingChanged = $scheduleChanged
            || $request->hasAny([
                'propertyDetails',
                'numberOfWorkers',
                'preferredWorkerId',
                'assignmentMode',
                'addressLatitude',
                'addressLongitude',
            ]);

        $updated = DB::transaction(function () use ($service, $multiDayPricing, $model, $validated, $scheduleInput, $scheduleChanged, $eventPricingChanged, $scheduleService, $sessionPricing): CleaningBooking {
            $updated = $service->update($model, $validated);

            if ($updated->isEventAssistanceBooking() && ($scheduleChanged || ($eventPricingChanged && $updated->sessions()->doesntExist()))) {
                $updated = $scheduleService->sync($updated, array_merge($validated, [
                    'schedule' => is_array($scheduleInput) ? $scheduleInput : null,
                ]));
            }

            if ($updated->isEventAssistanceBooking() && $eventPricingChanged && $updated->sessions()->exists()) {
                $sessions = $updated->sessions()->orderBy('sequence')->get()
                    ->map(static fn ($session): array => [
                        'date' => $session->scheduled_date->toDateString(),
                        'time' => (string) $session->scheduled_time,
                        'hours' => (float) $session->duration_hours,
                    ])->all();
                $quote = $multiDayPricing->quote(
                    propertyDetails: (array) $updated->property_details,
                    sessions: $sessions,
                    workerCount: max(1, (int) $updated->number_of_workers),
                    addressLatitude: $updated->address_latitude,
                    addressLongitude: $updated->address_longitude,
                    preferredWorkerId: null,
                );
                $propertyDetails = is_array($updated->property_details) ? $updated->property_details : [];
                $propertyDetails['hours'] = (float) $quote['schedule']['totalHours'];
                $updated->forceFill([
                    'property_details' => $propertyDetails,
                    'estimated_hours' => (float) $quote['schedule']['totalHours'],
                    'total_hours' => (float) $quote['schedule']['totalHours'],
                    'base_price' => (float) $quote['pricing']['basePrice'],
                    'addons_total' => (float) $quote['pricing']['addonsTotal'],
                    'travel_fee' => 0,
                    'travel_distance_km' => null,
                    'admin_margin_amount' => (float) $quote['pricing']['adminMargin'],
                    'is_pricing_final' => false,
                    'total_price' => (float) $quote['pricing']['totalPrice'],
                ])->saveQuietly();
                $updated = $sessionPricing->initializeFromParent($updated->fresh(['sessions']));
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
