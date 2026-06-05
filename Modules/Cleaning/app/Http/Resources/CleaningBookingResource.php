<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

/**
 * @mixin CleaningBooking
 */
final class CleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $normalizedPropertyDetails = $this->normalizedPropertyDetails();

        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'workerId' => $this->worker_id,
            'preferredWorkerId' => $this->preferred_worker_id,
            'assignmentMode' => $this->resolvedAssignmentMode(),
            'numberOfWorkers' => (int) ($this->number_of_workers ?? 1),
            'workerAcceptance' => $this->workerAcceptanceSummary(),
            'genderPreference' => $this->gender_preference?->value ?? $this->gender_preference,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'billingPolicyId' => $this->billing_policy_id,
            'bookingNumber' => $this->booking_number,
            'status' => $this->status?->value ?? $this->status,
            'propertyType' => $this->property_type,
            'propertyDetails' => $normalizedPropertyDetails,
            'addressLatitude' => $this->address_latitude !== null ? (float) $this->address_latitude : null,
            'addressLongitude' => $this->address_longitude !== null ? (float) $this->address_longitude : null,
            'locationName' => Arr::get($normalizedPropertyDetails, 'location_name') ?? Arr::get($normalizedPropertyDetails, 'address') ?? $this->property_type,
            'numberOfRooms' => Arr::get($normalizedPropertyDetails, 'bedrooms') ?? Arr::get($normalizedPropertyDetails, 'rooms'),
            'numberOfKitchens' => Arr::get($normalizedPropertyDetails, 'kitchens', 0),
            'numberOfBalconies' => Arr::get($normalizedPropertyDetails, 'balconies', 0),
            'estimatedSqm' => $this->estimated_sqm,
            'estimatedHours' => $this->estimated_hours,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'totalHours' => (float) $this->total_hours,
            'basePrice' => (float) $this->base_price,
            'addonsTotal' => (float) $this->addons_total,
            'extensionFeeTotal' => (float) ($this->extension_fee_total ?? 0),
            'travelFee' => (float) $this->travel_fee,
            'travelDistanceKm' => $this->travel_distance_km !== null ? (float) $this->travel_distance_km : null,
            'adminMargin' => (float) ($this->admin_margin_amount ?? 0),
            'isPricingFinal' => (bool) $this->is_pricing_final,
            'cancellationFee' => (float) $this->cancellation_fee,
            'totalPrice' => (float) $this->total_price,
            'termsAccepted' => $this->terms_accepted,
            'workStartedAt' => $this->work_started_at?->toDateTimeString(),
            'workFinishedAt' => $this->work_finished_at?->toDateTimeString(),
            'startedTravelAt' => $this->started_travel_at?->toDateTimeString(),
            'arrivedAt' => $this->arrived_at?->toDateTimeString(),
            'customerConfirmedAt' => $this->customer_confirmed_at?->toDateTimeString(),
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
            'preferredWorker' => $this->whenLoaded('preferredWorker', fn () => $this->preferredWorker ? $this->serializeWorker($this->preferredWorker) : null),
            'worker' => $this->whenLoaded('worker', function () {
                return $this->worker ? $this->serializeWorker($this->worker) : null;
            }),
            'workerAssignments' => $this->whenLoaded('workerAssignments', function () {
                return $this->workerAssignments->map(
                    fn (CleaningBookingWorkerAssignment $assignment): array => $this->serializeWorkerAssignment($assignment)
                )->values();
            }),
            'workerRoomAssignments' => $this->whenLoaded('rooms', fn () => $this->serializeWorkerRoomAssignments()),
            'roomAssignments' => $this->whenLoaded('rooms', function () {
                return $this->rooms->map(
                    fn (CleaningBookingRoom $room): array => $this->serializeRoomAssignment($room)
                )->values();
            }),
            'myAssignment' => $this->serializeMyAssignment($request),
            'services' => $this->whenLoaded('services'),
            'addons' => $this->whenLoaded('addons'),
            'billingPolicy' => $this->whenLoaded('billingPolicy'),
            'timeWarnings' => $this->whenLoaded('timeWarnings'),
            'disputes' => $this->whenLoaded('disputes'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedPropertyDetails(): array
    {
        $propertyDetails = is_array($this->property_details) ? $this->property_details : [];

        if (! array_key_exists('kitchens', $propertyDetails) && array_key_exists('kitchen_included', $propertyDetails)) {
            $propertyDetails['kitchens'] = (bool) $propertyDetails['kitchen_included'] ? 1 : 0;
            unset($propertyDetails['kitchen_included']);
        }

        if (array_key_exists('cleaning_mode', $propertyDetails)) {
            $propertyDetails['cleaning_mode'] = $this->normalizeCleaningMode((string) $propertyDetails['cleaning_mode']);
        } elseif (! array_key_exists('event_type', $propertyDetails) && ! array_key_exists('eventType', $propertyDetails)) {
            $propertyDetails['cleaning_mode'] = 'regular';
        }

        return $propertyDetails;
    }

    private function normalizeCleaningMode(string $cleaningMode): string
    {
        $normalized = mb_strtolower(mb_trim($cleaningMode));

        return in_array($normalized, ['regular', 'deep'], true) ? $normalized : 'regular';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorker(object $worker): array
    {
        $workerUser = $worker->relationLoaded('user') ? $worker->user : null;
        $userAvatar = $workerUser?->getFirstMediaUrl('primary-image');
        $workerAvatar = method_exists($worker, 'getFirstMediaUrl') ? $worker->getFirstMediaUrl('primary-image') : '';
        $avatarUrl = $userAvatar !== '' ? $userAvatar : ($workerAvatar !== '' ? $workerAvatar : null);

        return [
            'id' => $worker->id,
            'firstName' => $worker->first_name,
            'name' => $workerUser?->name,
            'phone' => $workerUser?->phone,
            'averageRating' => $worker->average_rating !== null ? (float) $worker->average_rating : null,
            'totalCompletedJobs' => $worker->total_completed_jobs,
            'isVerified' => (bool) $worker->is_verified,
            'avatarUrl' => $avatarUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkerAssignment(CleaningBookingWorkerAssignment $assignment): array
    {
        $worker = $assignment->relationLoaded('worker') ? $assignment->worker : null;
        $roomIds = $this->relationLoaded('rooms')
            ? $this->rooms
                ->where('assigned_worker_id', $assignment->worker_id)
                ->pluck('id')
                ->values()
                ->all()
            : [];

        return [
            'id' => $assignment->id,
            'workerId' => $assignment->worker_id,
            'status' => $assignment->status?->value ?? $assignment->status,
            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
            'roomCount' => (int) $assignment->room_count,
            'roomsWeight' => (float) $assignment->rooms_weight,
            'serviceShareAmount' => (float) $assignment->service_share_amount,
            'travelFee' => (float) $assignment->travel_fee,
            'adminMarginAmount' => (float) $assignment->admin_margin_amount,
            'workerAmount' => (float) $assignment->worker_amount,
            'currency' => $assignment->currency,
            'roomIds' => $roomIds,
            'worker' => $worker ? $this->serializeWorker($worker) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRoomAssignment(CleaningBookingRoom $room): array
    {
        return [
            'id' => $room->id,
            'roomKey' => $room->room_key,
            'roomType' => $room->room_type,
            'roomSize' => $room->room_size,
            'displayLabel' => $room->display_label,
            'weight' => (float) $room->weight,
            'plannedWorkerSlot' => $room->planned_worker_slot !== null ? (int) $room->planned_worker_slot : null,
            'plannedPreferredWorkerId' => $room->planned_preferred_worker_id,
            'assignedWorkerId' => $room->assigned_worker_id,
            'assignmentSource' => $room->assignment_source?->value ?? $room->assignment_source,
            'assignedWorker' => $room->relationLoaded('assignedWorker') && $room->assignedWorker
                ? $this->serializeWorker($room->assignedWorker)
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeWorkerRoomAssignments(): array
    {
        $grouped = [];

        foreach ($this->rooms as $room) {
            if ($room->planned_worker_slot === null) {
                continue;
            }

            $slot = (int) $room->planned_worker_slot;

            $grouped[$slot] ??= [
                'workerSlot' => $slot,
                'preferredWorkerId' => $room->planned_preferred_worker_id,
                'roomsWeight' => 0.0,
                'rooms' => [],
            ];

            $grouped[$slot]['rooms'][] = [
                'roomKey' => $room->room_key,
                'roomType' => $room->room_type,
                'roomSize' => $room->room_size,
            ];
            $grouped[$slot]['roomsWeight'] = round($grouped[$slot]['roomsWeight'] + (float) $room->weight, 2);
        }

        ksort($grouped);

        return array_values(array_map(function (array $assignment): array {
            usort($assignment['rooms'], static fn (array $left, array $right): int => strcmp((string) $left['roomKey'], (string) $right['roomKey']));

            return $assignment;
        }, $grouped));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeMyAssignment(Request $request): ?array
    {
        $workerId = $request->user()?->worker?->id;

        if ($workerId === null) {
            return null;
        }

        $assignment = $this->workerAssignmentForWorker($workerId);

        if (! $assignment) {
            return null;
        }

        return $this->serializeWorkerAssignment($assignment);
    }
}
