<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Services\CleaningOrderUrgencyService;
use Modules\User\Services\UserCleaningOrderEstimationService;

/** @mixin CleaningBooking */
final class CleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $details = is_array($this->property_details) ? $this->property_details : [];
        $globalOrderStatus = $this->status?->value ?? $this->status;
        $myAssignmentModel = $this->currentWorkerAssignment($request);
        $pendingCompletionAssignments = $this->pendingCustomerCompletionAssignments();
        $pendingCompletionAssignment = $pendingCompletionAssignments[0] ?? null;
        $completionRequests = $this->completionRequestsPayload($pendingCompletionAssignments);
        $myAssignment = $myAssignmentModel instanceof CleaningBookingWorkerAssignment
            ? $this->serializeWorkerAssignment($myAssignmentModel)
            : null;
        $orderStatus = $this->responseStatusForRequest($myAssignmentModel, (string) $globalOrderStatus);
        $workerOrderStatus = $this->workerOrderStatus($myAssignmentModel, (string) $globalOrderStatus);
        $team = $this->workerAcceptanceSummary();
        $workerLifecycleSummary = $this->workerLifecycleSummary();
        $address = $this->addressPayload($details);
        $workTimer = $this->workTimerPayload((string) $orderStatus, $myAssignmentModel);
        $finishedServices = $pendingCompletionAssignment instanceof CleaningBookingWorkerAssignment
            ? $this->finishedSnapshot($pendingCompletionAssignment->worker_finished_cleaning_services, 'service')
            : $this->finishedSnapshot($this->worker_finished_cleaning_services, 'service');
        $finishedRooms = $pendingCompletionAssignment instanceof CleaningBookingWorkerAssignment
            ? $this->finishedSnapshot($pendingCompletionAssignment->worker_finished_property_rooms, 'room')
            : $this->finishedSnapshot($this->worker_finished_property_rooms, 'room');
        $urgency = app(CleaningOrderUrgencyService::class);
        $baseTitle = ($this->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE ? 'Event assistance order' : 'Cleaning order').' #'.$this->booking_number;
        $displayTitle = $urgency->displayTitle($baseTitle, $this->scheduled_date);
        $isHotOrder = $urgency->isHotOrder($this->scheduled_date);

        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'workerId' => $this->worker_id,
            'preferredWorkerId' => $this->preferred_worker_id,
            'assignmentMode' => $this->resolvedAssignmentMode(),
            'assignmentModeLabel' => $this->label($this->resolvedAssignmentMode()),
            'numberOfWorkers' => (int) ($this->number_of_workers ?? 1),
            'workerAcceptance' => $team,
            'workerLifecycleSummary' => $workerLifecycleSummary,
            'worker_lifecycle_summary' => $workerLifecycleSummary,
            'genderPreference' => $this->gender_preference?->value ?? $this->gender_preference,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'billingPolicyId' => $this->billing_policy_id,
            'bookingNumber' => $this->booking_number,
            'displayTitle' => $displayTitle,
            'display_title' => $displayTitle,
            'isHotOrder' => $isHotOrder,
            'is_hot_order' => $isHotOrder,
            'urgencyLabel' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_LABEL : null,
            'urgency_label' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_LABEL : null,
            'urgencyPrefix' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_PREFIX : null,
            'urgency_prefix' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_PREFIX : null,
            'status' => $orderStatus,
            'statusLabel' => $this->label($orderStatus),
            'globalStatus' => $globalOrderStatus,
            'global_status' => $globalOrderStatus,
            'order_status' => $globalOrderStatus,
            'order_status_label' => $this->label($globalOrderStatus),
            'worker_order_status' => $workerOrderStatus,
            'worker_order_status_label' => $this->label($workerOrderStatus),
            'type' => $this->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE ? 'events' : 'cleaning',
            'required_workers_count' => $team['required'],
            'accepted_workers_count' => $team['accepted'],
            'pending_workers_count' => $team['remaining'],
            'start_approved_workers_count' => $team['startApproved'],
            'not_start_approved_workers_count' => $team['notStartApproved'],
            'propertyType' => $this->property_type,
            'propertyTypeLabel' => $this->label($this->property_type),
            'propertyDetails' => $this->propertyDetailsWithLabels($details),
            'property_details' => $this->propertyDetailsWithLabels($details),
            'cleaning_services' => $this->normalizedCleaningServices(),
            'services' => $this->servicesPayload(),
            'neighborhoodId' => $this->neighborhood_id,
            'neighborhoodName' => $this->neighborhood_name,
            'address' => $address,
            'addressLatitude' => $this->address_latitude !== null ? (float) $this->address_latitude : null,
            'addressLongitude' => $this->address_longitude !== null ? (float) $this->address_longitude : null,
            'locationName' => $address['fullAddress'] ?? Arr::get($details, 'location_name') ?? $this->property_type,
            'numberOfRooms' => Arr::get($details, 'bedrooms') ?? Arr::get($details, 'rooms'),
            'numberOfKitchens' => Arr::get($details, 'kitchens', 0),
            'numberOfBalconies' => Arr::get($details, 'balconies', 0),
            'numberOfSheds' => Arr::get($details, 'sheds', 0),
            'estimatedSqm' => $this->estimated_sqm,
            'estimatedHours' => $this->estimated_hours,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'totalHours' => (float) $this->total_hours,
            'basePrice' => (float) ($this->base_price ?? 0),
            'servicePrice' => (float) ($this->base_price ?? 0),
            'service_price' => (float) ($this->base_price ?? 0),
            'addonsTotal' => (float) $this->addons_total,
            'extensionFeeTotal' => (float) ($this->extension_fee_total ?? 0),
            'extendedTimeRanges' => app(CleaningExtendedTimePricingService::class)->ranges(),
            'travelFee' => (float) $this->travel_fee,
            'deliveryFee' => (float) $this->travel_fee,
            'travelDistanceKm' => $this->travel_distance_km !== null ? (float) $this->travel_distance_km : null,
            'adminMargin' => (float) ($this->admin_margin_amount ?? 0),
            'isPricingFinal' => (bool) $this->is_pricing_final,
            'cancellationFee' => (float) $this->cancellation_fee,
            'totalPrice' => (float) $this->total_price,
            'currency' => (string) config('app.currency', 'SYP'),
            'termsAccepted' => $this->terms_accepted,
            'workStartedAt' => $this->workerTimestamp($myAssignmentModel, 'work_started_at', $this->work_started_at, 'dateTime'),
            'workFinishedAt' => $this->workerTimestamp($myAssignmentModel, 'work_finished_at', $pendingCompletionAssignment?->work_finished_at ?? $this->work_finished_at, 'dateTime'),
            'workerCompletionMessage' => $myAssignmentModel?->worker_completion_message ?? $pendingCompletionAssignment?->worker_completion_message ?? $this->worker_completion_message,
            'workerFinishedCleaningServices' => $finishedServices,
            'worker_finished_cleaning_services' => $finishedServices,
            'workerFinishedPropertyRooms' => $finishedRooms,
            'worker_finished_property_rooms' => $finishedRooms,
            'customerCompletionRejectionMessage' => $this->customer_completion_rejection_message,
            'completionRejectedAt' => $this->completion_rejected_at?->toIso8601String(),
            'completionRequest' => $this->completionRequestPayload($finishedServices, $finishedRooms, $pendingCompletionAssignment),
            'completionRequests' => $completionRequests,
            'completion_requests' => $completionRequests,
            'startedTravelAt' => $this->workerTimestamp($myAssignmentModel, 'started_travel_at', $this->started_travel_at, 'dateTime'),
            'arrivedAt' => $this->workerTimestamp($myAssignmentModel, 'arrived_at', $this->arrived_at, 'dateTime'),
            'customerConfirmedAt' => $this->customer_confirmed_at?->toDateTimeString(),
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'cancelledByRole' => $this->cancelled_by_role,
            'cancelled_by_role' => $this->cancelled_by_role,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name, 'email' => $this->customer->email, 'phone' => $this->customer->phone]),
            'preferredWorker' => $this->whenLoaded('preferredWorker', fn () => $this->preferredWorker ? $this->serializeWorker($this->preferredWorker) : null),
            'worker' => $this->whenLoaded('worker', fn () => $this->worker ? $this->serializeWorker($this->worker) : null),
            'workerAssignments' => $this->whenLoaded('workerAssignments', fn () => $this->workerAssignments->map(fn (CleaningBookingWorkerAssignment $assignment): array => $this->serializeWorkerAssignment($assignment))->values()),
            'workerRoomAssignments' => $this->whenLoaded('rooms', fn () => $this->serializeWorkerRoomAssignments()),
            'roomAssignments' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn (CleaningBookingRoom $room): array => $this->serializeRoomAssignment($room))->values()),
            'myAssignment' => $myAssignment,
            'worker_assignment' => $myAssignment,
            'addons' => $this->whenLoaded('addons'),
            'billingPolicy' => $this->whenLoaded('billingPolicy'),
            'timeWarnings' => $this->whenLoaded('timeWarnings'),
            'disputes' => $this->whenLoaded('disputes'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
            'workTimer' => $workTimer,
            'expectedFinishAt' => $workTimer['expectedFinishAt'],
            'remainingWorkSeconds' => $workTimer['remainingWorkSeconds'],
            'overdueWorkSeconds' => $workTimer['overdueWorkSeconds'],
            'isWorkOverdue' => $workTimer['isWorkOverdue'],
            'shouldShowWorkTimer' => $workTimer['shouldShowWorkTimer'],
        ];
    }

    private function workerAcceptanceSummary(): array
    {
        $required = max(1, (int) ($this->number_of_workers ?? 1));
        $accepted = $this->acceptedWorkerCount();
        $startApproved = $this->startApprovedWorkerCount();
        return ['required' => $required, 'accepted' => $accepted, 'remaining' => max(0, $required - $accepted), 'isFulfilled' => $accepted >= $required, 'startApproved' => $startApproved, 'notStartApproved' => max(0, $accepted - $startApproved)];
    }

    private function workerLifecycleSummary(): array
    {
        $assignments = $this->workerAssignmentsForResource();
        $required = max(1, (int) ($this->number_of_workers ?? 1));
        $counts = array_fill_keys([
            'pending',
            'accepted',
            'accepted_waiting_for_order_start',
            'awaiting_start_verification',
            'start_approved',
            'in_progress',
            'awaiting_customer_completion',
            'time_extension_requested',
            'completed',
            'rejected',
            'withdrawn',
            'cancelled',
        ], 0);

        foreach ($assignments as $assignment) {
            $status = $this->assignmentStatus($assignment);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $accepted = 0;
        foreach ($assignments as $assignment) {
            if (in_array($this->assignmentStatus($assignment), CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                $accepted++;
            }
        }

        $completed = (int) ($counts[CleaningBookingWorkerAssignmentStatus::Completed->value] ?? 0);

        return [
            'required' => $required,
            'accepted' => $accepted,
            'remaining' => max(0, $required - $accepted),
            'pending' => (int) ($counts['pending'] ?? 0),
            'acceptedWaitingForOrderStart' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value] ?? 0),
            'awaitingStartVerification' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value] ?? 0),
            'startApproved' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::StartApproved->value] ?? 0),
            'inProgress' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::InProgress->value] ?? 0),
            'awaitingCustomerCompletion' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value] ?? 0),
            'timeExtensionRequested' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value] ?? 0),
            'completed' => $completed,
            'rejected' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::Rejected->value] ?? 0),
            'withdrawn' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::Withdrawn->value] ?? 0),
            'cancelled' => (int) ($counts[CleaningBookingWorkerAssignmentStatus::Cancelled->value] ?? 0),
            'isFullyCompleted' => $completed >= $required,
        ];
    }

    private function propertyDetailsWithLabels(array $details): array
    {
        foreach (['living_room_size', 'room_size', 'room_type', 'cleaning_mode', 'event_type', 'venue_type'] as $key) {
            if (array_key_exists($key, $details)) $details[$key.'_label'] = $this->label($details[$key]);
        }
        return $details;
    }

    private function addressPayload(array $details): array
    {
        $full = Arr::get($details, 'full_address') ?? Arr::get($details, 'address') ?? Arr::get($details, 'location_name');
        return ['fullAddress' => $full, 'full_address' => $full, 'locationName' => Arr::get($details, 'location_name'), 'location_name' => Arr::get($details, 'location_name'), 'neighborhoodId' => $this->neighborhood_id, 'neighborhoodName' => $this->neighborhood_name, 'latitude' => $this->address_latitude !== null ? (float) $this->address_latitude : null, 'longitude' => $this->address_longitude !== null ? (float) $this->address_longitude : null, 'building' => Arr::get($details, 'building'), 'floor' => Arr::get($details, 'floor'), 'apartment' => Arr::get($details, 'apartment'), 'notes' => Arr::get($details, 'address_notes') ?? Arr::get($details, 'notes')];
    }

    private function normalizedCleaningServices(): ?array
    {
        if (! is_array($this->cleaning_services)) return null;
        $items = array_values(array_filter(array_map(static fn (mixed $service): ?string => is_string($service) && trim($service) !== '' ? trim($service) : null, $this->cleaning_services)));
        return $items !== [] ? $items : null;
    }

    private function servicesPayload(): array
    {
        $services = $this->normalizedCleaningServices() ?? [];
        return array_values(array_map(static fn (string $service, int $index): array => ['id' => null, 'name' => $service, 'quantity' => 1, 'unitPrice' => null, 'unit_price' => null, 'totalPrice' => null, 'total_price' => null, 'sort' => $index], $services, array_keys($services)));
    }

    private function finishedSnapshot(mixed $items, string $type): array
    {
        if (! is_array($items)) return [];
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $text = trim($item);
                if ($text !== '') $out[] = ['name' => $text, 'label' => $text];
                continue;
            }
            if (! is_array($item)) continue;
            $payload = [];
            if (is_numeric($item['id'] ?? null)) $payload['id'] = (int) $item['id'];
            foreach (['name', 'label', 'roomKey', 'room_key', 'roomType', 'room_type', 'roomTypeLabel', 'room_type_label', 'displayLabel', 'display_label'] as $key) {
                $value = Arr::get($item, $key);
                if (is_string($value) && trim($value) !== '') $payload[$key] = trim($value);
            }
            $payload['label'] ??= $payload['name'] ?? $payload['displayLabel'] ?? $payload['display_label'] ?? $payload['roomTypeLabel'] ?? $payload['room_type_label'] ?? $payload['roomType'] ?? $payload['room_type'] ?? $payload['roomKey'] ?? $payload['room_key'] ?? null;
            if ($type === 'service' && ! isset($payload['name']) && isset($payload['label'])) $payload['name'] = $payload['label'];
            $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null && $v !== '');
            if ($payload !== []) $out[] = $payload;
        }
        return array_values($out);
    }

    private function serializeWorker(object $worker): array
    {
        $user = $worker->relationLoaded('user') ? $worker->user : null;
        return ['id' => $worker->id, 'firstName' => $worker->first_name, 'name' => $user?->name, 'phone' => $user?->phone, 'averageRating' => $worker->average_rating !== null ? (float) $worker->average_rating : null, 'totalCompletedJobs' => $worker->total_completed_jobs, 'isVerified' => (bool) $worker->is_verified, 'avatarUrl' => null];
    }

    private function serializeWorkerAssignment(CleaningBookingWorkerAssignment $assignment): array
    {
        $worker = $assignment->relationLoaded('worker') ? $assignment->worker : null;
        $roomIds = $this->relationLoaded('rooms') ? $this->rooms->where('assigned_worker_id', $assignment->worker_id)->pluck('id')->values()->all() : [];
        $assignmentStatus = $this->assignmentStatusForResponse($assignment);
        $services = $this->finishedSnapshot($assignment->worker_finished_cleaning_services, 'service');
        $rooms = $this->finishedSnapshot($assignment->worker_finished_property_rooms, 'room');

        return [
            'id' => $assignment->id,
            'workerId' => $assignment->worker_id,
            'status' => $assignmentStatus,
            'statusLabel' => $this->label($assignmentStatus),
            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
            'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
            'startApprovedAt' => $assignment->start_approved_at?->toIso8601String(),
            'workStartedAt' => $assignment->work_started_at?->toIso8601String(),
            'workFinishedAt' => $assignment->work_finished_at?->toIso8601String(),
            'workerCompletionMessage' => $assignment->worker_completion_message,
            'workerFinishedCleaningServices' => $services,
            'worker_finished_cleaning_services' => $services,
            'workerFinishedPropertyRooms' => $rooms,
            'worker_finished_property_rooms' => $rooms,
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

    private function serializeRoomAssignment(CleaningBookingRoom $room): array
    {
        return ['id' => $room->id, 'roomKey' => $room->room_key, 'roomType' => $room->room_type, 'roomTypeLabel' => $this->label($room->room_type), 'roomSize' => $room->room_size, 'roomSizeLabel' => $this->label($room->room_size), 'displayLabel' => $room->display_label, 'weight' => (float) $room->weight, 'plannedWorkerSlot' => $room->planned_worker_slot !== null ? (int) $room->planned_worker_slot : null, 'plannedPreferredWorkerId' => $room->planned_preferred_worker_id, 'assignedWorkerId' => $room->assigned_worker_id, 'assignmentSource' => $room->assignment_source?->value ?? $room->assignment_source, 'assignmentSourceLabel' => $this->label($room->assignment_source?->value ?? $room->assignment_source), 'assignedWorker' => $room->relationLoaded('assignedWorker') && $room->assignedWorker ? $this->serializeWorker($room->assignedWorker) : null];
    }

    private function serializeWorkerRoomAssignments(): array
    {
        if (! $this->relationLoaded('rooms')) return [];
        return $this->rooms->groupBy('planned_worker_slot')->filter(fn ($_, $slot): bool => $slot !== '')->map(fn ($rooms, $slot): array => ['workerSlot' => (int) $slot, 'preferredWorkerId' => $rooms->first()?->planned_preferred_worker_id, 'roomsWeight' => round((float) $rooms->sum('weight'), 2), 'rooms' => $rooms->map(fn ($room): array => ['roomKey' => $room->room_key, 'roomType' => $room->room_type, 'roomTypeLabel' => $this->label($room->room_type), 'roomSize' => $room->room_size, 'roomSizeLabel' => $this->label($room->room_size)])->values()->all()])->values()->all();
    }

    private function currentWorkerAssignment(Request $request): ?CleaningBookingWorkerAssignment
    {
        $workerId = $request->user()?->worker?->id;
        if ($workerId === null) return null;
        $assignment = $this->relationLoaded('workerAssignments') ? $this->workerAssignments->firstWhere('worker_id', $workerId) : $this->workerAssignments()->where('worker_id', $workerId)->first();
        return $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
    }

    /** @return array<int, CleaningBookingWorkerAssignment> */
    private function pendingCustomerCompletionAssignments(): array
    {
        $assignments = $this->workerAssignmentsForResource();

        return array_values(array_filter($assignments, function (CleaningBookingWorkerAssignment $assignment): bool {
            return $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value;
        }));
    }

    /** @return array<int, CleaningBookingWorkerAssignment> */
    private function workerAssignmentsForResource(): array
    {
        $assignments = $this->relationLoaded('workerAssignments')
            ? $this->workerAssignments
            : $this->workerAssignments()->with('worker.user')->get();

        return $assignments
            ->sortBy(fn (CleaningBookingWorkerAssignment $assignment): string => sprintf(
                '%020d-%010d',
                $assignment->work_finished_at?->getTimestamp() ?? 0,
                (int) $assignment->id,
            ))
            ->values()
            ->all();
    }

    private function completionRequestPayload(array $services, array $rooms, ?CleaningBookingWorkerAssignment $assignment = null): array
    {
        $status = $this->status instanceof CleaningBookingStatus ? $this->status : CleaningBookingStatus::tryFrom((string) $this->status);
        $awaiting = $status === CleaningBookingStatus::AwaitingCustomerCompletion || $assignment instanceof CleaningBookingWorkerAssignment;
        $message = $assignment?->worker_completion_message ?? $this->worker_completion_message;
        $requestedAt = $assignment?->work_finished_at ?? $this->work_finished_at;

        return [
            'isAwaitingCustomerConfirmation' => $awaiting,
            'message' => $message,
            'requestedAt' => $requestedAt?->toIso8601String(),
            'expiresAt' => $awaiting && $requestedAt !== null ? $requestedAt->copy()->addMinutes(30)->toIso8601String() : null,
            'workerId' => $assignment?->worker_id,
            'assignmentId' => $assignment?->id,
            'worker' => $assignment?->relationLoaded('worker') && $assignment->worker ? $this->serializeWorker($assignment->worker) : null,
            'finishedCleaningServices' => $services,
            'finished_cleaning_services' => $services,
            'finishedPropertyRooms' => $rooms,
            'finished_property_rooms' => $rooms,
            'actions' => ['canConfirm' => $awaiting, 'canReject' => $awaiting, 'canRequestExtension' => $awaiting],
        ];
    }

    /** @param array<int, CleaningBookingWorkerAssignment> $assignments */
    private function completionRequestsPayload(array $assignments): array
    {
        if ($assignments === []) {
            $legacy = $this->completionRequestPayload(
                $this->finishedSnapshot($this->worker_finished_cleaning_services, 'service'),
                $this->finishedSnapshot($this->worker_finished_property_rooms, 'room'),
                null,
            );

            return $legacy['isAwaitingCustomerConfirmation'] ? [$legacy] : [];
        }

        return array_values(array_map(function (CleaningBookingWorkerAssignment $assignment): array {
            return $this->completionRequestPayload(
                $this->finishedSnapshot($assignment->worker_finished_cleaning_services, 'service'),
                $this->finishedSnapshot($assignment->worker_finished_property_rooms, 'room'),
                $assignment,
            );
        }, $assignments));
    }

    private function workerOrderStatus(?CleaningBookingWorkerAssignment $assignment, string $globalStatus): string
    {
        if (in_array($globalStatus, [
            CleaningBookingStatus::Cancelled->value,
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::UnderDispute->value,
        ], true)) {
            return $globalStatus;
        }

        return $assignment instanceof CleaningBookingWorkerAssignment ? $this->assignmentStatusForResponse($assignment) : $globalStatus;
    }

    private function responseStatusForRequest(?CleaningBookingWorkerAssignment $assignment, string $globalStatus): string
    {
        if (! $assignment instanceof CleaningBookingWorkerAssignment) {
            return $globalStatus;
        }

        if (in_array($globalStatus, [
            CleaningBookingStatus::Cancelled->value,
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::UnderDispute->value,
        ], true)) {
            return $globalStatus;
        }

        $assignmentStatus = $this->assignmentStatus($assignment);

        if (
            $globalStatus === CleaningBookingStatus::AwaitingWorkerStartConfirmation->value
            && $assignmentStatus === CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value
        ) {
            return $assignment->arrived_at !== null
                ? CleaningBookingStatus::AwaitingWorkerStartConfirmation->value
                : CleaningBookingStatus::WorkerAssigned->value;
        }

        return match ($assignmentStatus) {
            CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value => $assignment->arrived_at !== null
                ? CleaningBookingStatus::AwaitingStartVerification->value
                : CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingWorkerAssignmentStatus::StartApproved->value => CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
            CleaningBookingWorkerAssignmentStatus::InProgress->value => CleaningBookingStatus::InProgress->value,
            CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value => CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value => CleaningBookingStatus::TimeExtensionRequested->value,
            CleaningBookingWorkerAssignmentStatus::Completed->value => CleaningBookingStatus::Completed->value,
            default => $globalStatus === CleaningBookingStatus::Pending->value
                ? CleaningBookingStatus::Pending->value
                : CleaningBookingStatus::WorkerAssigned->value,
        };
    }

    private function assignmentStatusForResponse(CleaningBookingWorkerAssignment $assignment): string
    {
        $assignmentStatus = $this->assignmentStatus($assignment);
        $globalStatus = $this->status instanceof CleaningBookingStatus
            ? $this->status->value
            : (string) $this->status;

        if (
            $globalStatus === CleaningBookingStatus::AwaitingWorkerStartConfirmation->value
            && $assignmentStatus === CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value
            && $assignment->arrived_at !== null
        ) {
            return CleaningBookingStatus::AwaitingWorkerStartConfirmation->value;
        }

        return $assignmentStatus;
    }

    private function assignmentStatus(CleaningBookingWorkerAssignment $assignment): string
    {
        return $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
    }

    private function label(mixed $value): ?string
    {
        if ($value === null) return null;
        return str_replace('_', ' ', (string) $value);
    }

    private function workerTimestamp(?CleaningBookingWorkerAssignment $assignment, string $field, mixed $fallback, string $format): ?string
    {
        $value = $assignment instanceof CleaningBookingWorkerAssignment ? $assignment->{$field} : null;
        $value ??= $fallback;

        if ($value === null) {
            return null;
        }

        return $format === 'iso' ? $value->toIso8601String() : $value->toDateTimeString();
    }

    private function workTimerPayload(string $status, ?CleaningBookingWorkerAssignment $assignment = null): array
    {
        $start = $assignment?->work_started_at ?? $this->work_started_at ?? $this->arrived_at;
        $hours = (float) ($this->total_hours ?: $this->estimated_hours ?: 0);
        if ($start === null || $hours <= 0) return ['timerStartAt' => $start?->toIso8601String(), 'expectedFinishAt' => null, 'durationHours' => $hours > 0 ? $hours : null, 'remainingWorkSeconds' => 0, 'overdueWorkSeconds' => 0, 'isWorkOverdue' => false, 'shouldShowWorkTimer' => false, 'source' => ['startField' => null, 'durationField' => null]];
        $expected = $start->copy()->addSeconds((int) round($hours * 3600));
        $diff = now()->diffInSeconds($expected, false);
        $show = in_array($status, [CleaningBookingStatus::InProgress->value, CleaningBookingStatus::TimeExtensionRequested->value], true);
        return ['timerStartAt' => $start->toIso8601String(), 'expectedFinishAt' => $expected->toIso8601String(), 'durationHours' => $hours, 'remainingWorkSeconds' => $show ? max(0, $diff) : 0, 'overdueWorkSeconds' => $show ? max(0, -$diff) : 0, 'isWorkOverdue' => $show && $diff < 0, 'shouldShowWorkTimer' => $show, 'source' => ['startField' => $assignment?->work_started_at !== null ? 'assignment.work_started_at' : ($this->work_started_at !== null ? 'work_started_at' : 'arrived_at'), 'durationField' => $this->total_hours ? 'total_hours' : 'estimated_hours']];
    }
}
