<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\User\Services\UserCleaningOrderEstimationService;

/**
 * @mixin CleaningBooking
 */
final class CleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $normalizedPropertyDetails = $this->normalizedPropertyDetails();
        $propertyDetailsWithLabels = $this->propertyDetailsWithLabels($normalizedPropertyDetails);
        $myAssignment = $this->serializeMyAssignment($request);
        $orderStatus = $this->status?->value ?? $this->status;
        $workerOrderStatus = $this->workerOrderStatus($myAssignment, $orderStatus);
        $teamSummary = $this->workerAcceptanceSummary();
        $address = $this->addressPayload($normalizedPropertyDetails);
        $servicePrice = (float) ($this->base_price ?? 0);

        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'workerId' => $this->worker_id,
            'preferredWorkerId' => $this->preferred_worker_id,
            'assignmentMode' => $this->resolvedAssignmentMode(),
            'assignmentModeLabel' => $this->enumLabel('assignment_mode', $this->resolvedAssignmentMode()),
            'numberOfWorkers' => (int) ($this->number_of_workers ?? 1),
            'workerAcceptance' => $this->workerAcceptanceSummary(),
            'genderPreference' => $this->gender_preference?->value ?? $this->gender_preference,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'billingPolicyId' => $this->billing_policy_id,
            'bookingNumber' => $this->booking_number,
            'status' => $orderStatus,
            'statusLabel' => $this->enumLabel('booking_status', $orderStatus),
            'order_status' => $orderStatus,
            'order_status_label' => $this->enumLabel('booking_status', $orderStatus),
            'worker_order_status' => $workerOrderStatus,
            'worker_order_status_label' => $this->enumLabel('booking_status', $workerOrderStatus),
            'type' => $this->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE ? 'events' : 'cleaning',
            'required_workers_count' => $teamSummary['required'],
            'accepted_workers_count' => $teamSummary['accepted'],
            'pending_workers_count' => $teamSummary['remaining'],
            'start_approved_workers_count' => $teamSummary['startApproved'],
            'not_start_approved_workers_count' => $teamSummary['notStartApproved'],
            'propertyType' => $this->property_type,
            'propertyTypeLabel' => $this->enumLabel('property_type', $this->property_type),
            'propertyDetails' => $propertyDetailsWithLabels,
            'property_details' => $propertyDetailsWithLabels,
            'cleaning_services' => $this->normalizedCleaningServices(),
            'services' => $this->servicesPayload(),
            'address' => $address,
            'addressLatitude' => $this->address_latitude !== null ? (float) $this->address_latitude : null,
            'addressLongitude' => $this->address_longitude !== null ? (float) $this->address_longitude : null,
            'locationName' => $address['fullAddress'] ?? Arr::get($normalizedPropertyDetails, 'location_name') ?? $this->property_type,
            'numberOfRooms' => Arr::get($normalizedPropertyDetails, 'bedrooms') ?? Arr::get($normalizedPropertyDetails, 'rooms'),
            'numberOfKitchens' => Arr::get($normalizedPropertyDetails, 'kitchens', 0),
            'numberOfBalconies' => Arr::get($normalizedPropertyDetails, 'balconies', 0),
            'estimatedSqm' => $this->estimated_sqm,
            'estimatedHours' => $this->estimated_hours,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'totalHours' => (float) $this->total_hours,
            'basePrice' => $servicePrice,
            'servicePrice' => $servicePrice,
            'service_price' => $servicePrice,
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
            'workStartedAt' => $this->work_started_at?->toDateTimeString(),
            'workFinishedAt' => $this->work_finished_at?->toDateTimeString(),
            'workerCompletionMessage' => $this->worker_completion_message,
            'customerCompletionRejectionMessage' => $this->customer_completion_rejection_message,
            'completionRejectedAt' => $this->completion_rejected_at?->toIso8601String(),
            'completionRequest' => $this->completionRequestPayload(),
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
            'myAssignment' => $myAssignment,
            'worker_assignment' => $myAssignment,
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

    /** @param array<string, mixed> $propertyDetails */
    private function propertyDetailsWithLabels(array $propertyDetails): array
    {
        foreach ([
            'living_room_size' => 'living_room_size',
            'room_size' => 'room_size',
            'room_type' => 'room_type',
            'cleaning_mode' => 'cleaning_mode',
            'event_type' => 'event_type',
            'venue_type' => 'venue_type',
        ] as $key => $group) {
            if (array_key_exists($key, $propertyDetails)) {
                $propertyDetails[$key.'_label'] = $this->enumLabel($group, $propertyDetails[$key]);
            }
        }

        return $propertyDetails;
    }

    /** @param array<string, mixed> $propertyDetails */
    private function addressPayload(array $propertyDetails): array
    {
        $fullAddress = Arr::get($propertyDetails, 'full_address')
            ?? Arr::get($propertyDetails, 'address')
            ?? Arr::get($propertyDetails, 'location_name');

        return [
            'fullAddress' => $fullAddress,
            'full_address' => $fullAddress,
            'locationName' => Arr::get($propertyDetails, 'location_name'),
            'location_name' => Arr::get($propertyDetails, 'location_name'),
            'latitude' => $this->address_latitude !== null ? (float) $this->address_latitude : null,
            'longitude' => $this->address_longitude !== null ? (float) $this->address_longitude : null,
            'building' => Arr::get($propertyDetails, 'building'),
            'floor' => Arr::get($propertyDetails, 'floor'),
            'apartment' => Arr::get($propertyDetails, 'apartment'),
            'notes' => Arr::get($propertyDetails, 'address_notes') ?? Arr::get($propertyDetails, 'notes'),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizedCleaningServices(): ?array
    {
        if (! is_array($this->cleaning_services)) {
            return null;
        }

        $services = array_values(array_filter(
            array_map(
                static fn (mixed $service): ?string => is_string($service) && mb_trim($service) !== ''
                    ? mb_trim($service)
                    : null,
                $this->cleaning_services
            ),
            static fn (?string $service): bool => $service !== null
        ));

        return $services !== [] ? $services : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function servicesPayload(): array
    {
        $services = $this->normalizedCleaningServices() ?? [];

        return array_values(array_map(
            static fn (string $service, int $index): array => [
                'id' => null,
                'name' => $service,
                'quantity' => 1,
                'unitPrice' => null,
                'unit_price' => null,
                'totalPrice' => null,
                'total_price' => null,
                'sort' => $index,
            ],
            $services,
            array_keys($services),
        ));
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
            'statusLabel' => $this->enumLabel('booking_status', $assignment->status?->value ?? $assignment->status),
            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
            'startApprovedAt' => $assignment->start_approved_at?->toIso8601String(),
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
            'roomTypeLabel' => $this->enumLabel('room_type', $room->room_type),
            'roomSize' => $room->room_size,
            'roomSizeLabel' => $this->enumLabel('room_size', $room->room_size),
            'displayLabel' => $room->display_label,
            'weight' => (float) $room->weight,
            'plannedWorkerSlot' => $room->planned_worker_slot !== null ? (int) $room->planned_worker_slot : null,
            'plannedPreferredWorkerId' => $room->planned_preferred_worker_id,
            'assignedWorkerId' => $room->assigned_worker_id,
            'assignmentSource' => $room->assignment_source?->value ?? $room->assignment_source,
            'assignmentSourceLabel' => $this->enumLabel('assignment_source', $room->assignment_source?->value ?? $room->assignment_source),
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
                'roomTypeLabel' => $this->enumLabel('room_type', $room->room_type),
                'roomSize' => $room->room_size,
                'roomSizeLabel' => $this->enumLabel('room_size', $room->room_size),
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

    /**
     * @return array<string, mixed>
     */
    private function completionRequestPayload(): array
    {
        $status = $this->status instanceof CleaningBookingStatus
            ? $this->status
            : CleaningBookingStatus::tryFrom((string) $this->status);
        $isAwaitingCustomerConfirmation = $status === CleaningBookingStatus::AwaitingCustomerCompletion;

        return [
            'isAwaitingCustomerConfirmation' => $isAwaitingCustomerConfirmation,
            'message' => $this->worker_completion_message,
            'requestedAt' => $this->work_finished_at?->toIso8601String(),
            'expiresAt' => $isAwaitingCustomerConfirmation && $this->work_finished_at !== null
                ? $this->work_finished_at->copy()->addMinutes(30)->toIso8601String()
                : null,
            'actions' => [
                'canConfirm' => $isAwaitingCustomerConfirmation,
                'canReject' => $isAwaitingCustomerConfirmation,
                'canRequestExtension' => $isAwaitingCustomerConfirmation,
            ],
        ];
    }

    private function workerAssignmentForWorker(int $workerId): ?CleaningBookingWorkerAssignment
    {
        $assignment = $this->relationLoaded('workerAssignments')
            ? $this->workerAssignments->firstWhere('worker_id', $workerId)
            : $this->workerAssignments()->where('worker_id', $workerId)->first();

        return $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
    }

    private function workerOrderStatus(?array $myAssignment, ?string $orderStatus): ?string
    {
        if (in_array($orderStatus, ['cancelled', 'completed', 'in_progress', 'awaiting_customer_completion', 'time_extension_requested'], true)) {
            return $orderStatus;
        }

        if ($myAssignment !== null && isset($myAssignment['status'])) {
            return (string) $myAssignment['status'];
        }

        return $orderStatus;
    }

    private function enumLabel(string $group, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower((string) $value);

        return match ($group) {
            'booking_status' => match ($normalized) {
                'pending' => 'قيد الانتظار',
                'accepted_waiting_for_order_start' => 'تم القبول بانتظار بدء الطلب',
                'accepted_waiting_team' => 'تم القبول بانتظار اكتمال الفريق',
                'worker_assigned' => 'تم تعيين العامل',
                'awaiting_start_verification' => 'بانتظار تأكيد بدء العمل',
                'in_progress' => 'قيد التنفيذ',
                'awaiting_customer_completion' => 'بانتظار تأكيد العميل للإنهاء',
                'time_extension_requested' => 'تم طلب تمديد الوقت',
                'completed' => 'مكتمل',
                'cancelled' => 'ملغي',
                'rejected' => 'مرفوض',
                'withdrawn' => 'منسحب',
                default => $this->humanizeEnum($normalized),
            },
            'property_type' => match ($normalized) {
                'apartment' => 'شقة',
                'villa' => 'فيلا',
                'house', 'home' => 'منزل',
                'office' => 'مكتب',
                'studio' => 'استوديو',
                'event_assistance' => 'مساعدة مناسبة',
                default => $this->humanizeEnum($normalized),
            },
            'living_room_size', 'room_size' => match ($normalized) {
                'small' => 'صغيرة',
                'medium' => 'متوسطة',
                'large' => 'كبيرة',
                'none' => 'لا يوجد',
                default => $this->humanizeEnum($normalized),
            },
            'room_type' => match ($normalized) {
                'bedroom' => 'غرفة نوم',
                'bathroom' => 'حمام',
                'living_room' => 'غرفة معيشة',
                'kitchen' => 'مطبخ',
                'balcony' => 'شرفة',
                'hall' => 'صالة',
                default => $this->humanizeEnum($normalized),
            },
            'cleaning_mode' => match ($normalized) {
                'regular' => 'تنظيف عادي',
                'deep' => 'تنظيف عميق',
                default => $this->humanizeEnum($normalized),
            },
            'assignment_mode' => match ($normalized) {
                'preferred_worker' => 'عامل محدد',
                'open_count' => 'عدد عمال مفتوح',
                default => $this->humanizeEnum($normalized),
            },
            'event_type' => match ($normalized) {
                'family_dinner' => 'عشاء عائلي',
                'birthday' => 'عيد ميلاد',
                'large_gathering' => 'تجمع كبير',
                'funeral' => 'عزاء',
                'other' => 'أخرى',
                default => $this->humanizeEnum($normalized),
            },
            'venue_type' => match ($normalized) {
                'home' => 'منزل',
                'hall' => 'قاعة',
                'outdoor' => 'خارجي',
                'office' => 'مكتب',
                'other' => 'أخرى',
                default => $this->humanizeEnum($normalized),
            },
            'assignment_source' => match ($normalized) {
                'customer' => 'العميل',
                'worker' => 'العامل',
                'system' => 'النظام',
                default => $this->humanizeEnum($normalized),
            },
            default => $this->humanizeEnum($normalized),
        };
    }

    private function humanizeEnum(string $value): string
    {
        return str_replace('_', ' ', $value);
    }
}
