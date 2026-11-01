<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Observers\CleaningBookingObserver;
use Modules\Cleaning\Support\CleaningRoomBlueprints;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingAdminPricingService
{
    private const MAX_ROOMS_PER_BUCKET = 30;

    public function __construct(
        private readonly CleaningBookingTeamService $teamService,
        private readonly UserCleaningOrderEstimationService $estimator,
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    public function setRoomQuantity(
        CleaningBooking $booking,
        string $roomType,
        string $roomSize,
        int $quantity,
    ): CleaningBooking {
        if (! in_array($roomType, CleaningRoomBlueprints::ROOM_TYPE_ORDER, true)
            || ! in_array($roomSize, CleaningRoomBlueprints::ROOM_SIZE_ORDER, true)
            || $quantity < 0 || $quantity > self::MAX_ROOMS_PER_BUCKET) {
            throw new InvalidArgumentException('نوع الغرفة أو حجمها أو عددها غير صالح.');
        }

        return DB::transaction(function () use ($booking, $roomType, $roomSize, $quantity): CleaningBooking {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertBookingIsEditable($booking);

            $details = is_array($booking->property_details) ? $booking->property_details : [];
            $breakdown = is_array($details['room_size_breakdown'] ?? null)
                ? $details['room_size_breakdown']
                : [];

            if ($breakdown === []) {
                foreach (CleaningRoomBlueprints::generateRoomBlueprints($details) as $room) {
                    $type = (string) $room['room_type'];
                    $size = (string) $room['room_size'];
                    $breakdown[$type][$size] = (int) ($breakdown[$type][$size] ?? 0) + 1;
                }
            }

            $breakdown[$roomType][$roomSize] = $quantity;
            $roomCount = 0;
            foreach (CleaningRoomBlueprints::ROOM_TYPE_ORDER as $type) {
                foreach (CleaningRoomBlueprints::ROOM_SIZE_ORDER as $size) {
                    $roomCount += max(0, (int) ($breakdown[$type][$size] ?? 0));
                }
            }
            if ($roomCount < 1 || $roomCount > 100) {
                throw new InvalidArgumentException('يجب أن يحتوي الطلب على غرفة واحدة على الأقل وبحد أقصى 100 غرفة.');
            }

            $details['room_size_breakdown'] = $breakdown;
            $estimate = $this->estimator->estimate((string) $booking->property_type, $details);
            // Match the customer-side safety rule: at most eight estimated
            // service hours per worker on ordinary cleaning requests.
            $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));
            if ((float) $estimate['estimatedHours'] > 8 * $requiredWorkers) {
                throw new InvalidArgumentException(
                    'المدة المقدرة تتجاوز 8 ساعات لكل عامل. عدّل عدد الغرف أو عدد العمال أولاً.',
                );
            }

            $pricing = $this->estimator->price(
                (string) $booking->property_type,
                $details,
                $booking->address_latitude,
                $booking->address_longitude,
                preferredWorkerId: null,
                serviceIds: null,
                requestMaterials: false,
                workerCount: max(1, (int) ($booking->number_of_workers ?? 1)),
            );

            // Repricing must carry the server-quoted fixed transport allowance
            // into team recalculation. Without this snapshot, a provisional
            // recalculation can silently replace the customer's new quote.
            $details['_booking_v2_transport_allowance_per_worker'] = round(
                (float) $pricing['travelFee'] / $requiredWorkers,
                2,
            );

            // The final team recalculation emits one canonical update. Suppress
            // the intermediate snapshot update to avoid duplicate customer alerts.
            CleaningBookingObserver::withoutLifecycleUpdateNotificationsFor(
                (int) $booking->id,
                function () use ($booking, $details, $estimate, $pricing): void {
                    $booking->forceFill([
                        'property_details' => $details,
                        'estimated_sqm' => $estimate['estimatedSqm'],
                        'estimated_hours' => $estimate['estimatedHours'],
                        'total_hours' => $estimate['estimatedHours'],
                        'base_price' => $pricing['basePrice'],
                    ])->save();
                },
            );

            // Room keys remain stable for unchanged rooms, preserving existing
            // worker assignments. Removed keys are deleted only before travel.
            $blueprints = CleaningRoomBlueprints::generateRoomBlueprints($details);
            $roomKeys = array_column($blueprints, 'room_key');
            $booking->rooms()->whereNotIn('room_key', $roomKeys)->delete();
            foreach ($blueprints as $room) {
                $booking->rooms()->firstOrCreate(
                    ['room_key' => $room['room_key']],
                    [
                        'room_type' => $room['room_type'],
                        'room_size' => $room['room_size'],
                        'display_label' => $room['display_label'],
                        'weight' => $room['weight'],
                    ],
                );
            }

            $booking->unsetRelation('rooms');

            return $this->teamService->recalculateBookingTeam(
                $booking,
                finalizeBooking: $booking->isTeamFulfilled(),
                administrativeReprice: true,
            );
        });
    }

    public function setBasePrice(CleaningBooking $booking, float $basePrice): CleaningBooking
    {
        if (! is_finite($basePrice) || $basePrice < 1) {
            throw new InvalidArgumentException('السعر الأساسي يجب أن يكون رقماً أكبر من صفر.');
        }

        return DB::transaction(function () use ($booking, $basePrice): CleaningBooking {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertBookingIsEditable($booking);

            // Preserve the order's authoritative per-worker allowance when
            // present. For legacy orders without a quote snapshot, obtain the
            // current configured fixed allowance from the backend, not the
            // old route-derived travel_fee stored on a booking.
            $details = (array) ($booking->property_details ?? []);
            if (! array_key_exists('_booking_v2_transport_allowance_per_worker', $details)) {
                $workerCount = max(1, (int) ($booking->number_of_workers ?? 1));
                $quotedTransport = $this->pricingCalculator->provisional(
                    0.0,
                    0.0,
                    0.0,
                    $workerCount,
                );
                $details['_booking_v2_transport_allowance_per_worker'] = round(
                    max(0.0, (float) $quotedTransport['travelFee']) / $workerCount,
                    2,
                );
            }

            CleaningBookingObserver::withoutLifecycleUpdateNotificationsFor(
                (int) $booking->id,
                fn () => $booking->forceFill([
                    'base_price' => round($basePrice, 2),
                    'property_details' => $details,
                ])->save(),
            );

            return $this->teamService->recalculateBookingTeam(
                $booking,
                finalizeBooking: $booking->isTeamFulfilled(),
                administrativeReprice: true,
            );
        });
    }

    private function assertBookingIsEditable(CleaningBooking $booking): void
    {
        if (! in_array($booking->status, [
            CleaningBookingStatus::Pending,
            CleaningBookingStatus::WorkerAssigned,
        ], true)
            || (string) ($booking->booking_kind ?? '') === 'open_time'
            || (string) $booking->property_type === 'event_assistance'
            || ! empty((array) data_get($booking->property_details, '_time_period_v2', []))
            || $booking->started_travel_at !== null
            || $booking->work_started_at !== null
            || (method_exists($booking, 'sessions') && $booking->sessions()->exists())
            || $booking->workerAssignments()
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->where(function ($query): void {
                    $query->whereNotNull('started_travel_at')
                        ->orWhereNotNull('work_started_at');
                })->exists()
        ) {
            throw new InvalidArgumentException(
                'يمكن تعديل الغرف والسعر فقط للطلبات العادية غير الجارية وقبل بدء رحلة أي عامل.',
            );
        }
    }
}
