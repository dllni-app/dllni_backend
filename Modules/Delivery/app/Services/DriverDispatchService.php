<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Jobs\ExpireAssignmentAttemptJob;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use RuntimeException;

final class DriverDispatchService
{
    private const NO_ELIGIBLE_DRIVERS_NOTE = 'No eligible drivers available within search radius.';
    private const DRIVER_LOCATION_UNAVAILABLE_NOTE = 'Selected driver has no recent location.';

    public function __construct(
        private readonly DriverLocationService $locationService,
        private readonly DeliveryOrderService $deliveryOrderService,
        private readonly DeliveryNotificationService $notifications,
        private readonly DeliverySourceOrderSyncService $sourceSync,
        private readonly DeliveryUserNotificationService $userNotifications,
    ) {}

    public function dispatchByOrderId(int $orderId): void
    {
        $attemptId = null;
        $redispatchOrderId = null;
        $redispatchDelaySeconds = null;

        DB::transaction(function () use ($orderId, &$attemptId, &$redispatchOrderId, &$redispatchDelaySeconds): void {
            $order = DeliveryOrder::query()->lockForUpdate()->find($orderId);

            if (! $order instanceof DeliveryOrder) {
                return;
            }

            if ($order->status !== DeliveryOrderStatus::Dispatching->value) {
                return;
            }

            $order->loadMissing('company');

            if ($order->company?->is_suspended) {
                $this->deliveryOrderService->markStopped($order, 'Company is suspended.');

                return;
            }

            $attemptCount = $order->assignmentAttempts()->count();
            $maxAttempts = (int) config('delivery.dispatch.max_attempts_per_order', 20);

            if ($attemptCount >= $maxAttempts) {
                $this->deliveryOrderService->markStopped($order, 'Maximum dispatch attempts reached.');

                return;
            }

            $candidate = $this->selectNextCandidate($order);

            if (! $candidate instanceof DeliveryDriver) {
                $this->retryWithoutCandidate(
                    order: $order,
                    reason: self::NO_ELIGIBLE_DRIVERS_NOTE,
                    redispatchOrderId: $redispatchOrderId,
                    redispatchDelaySeconds: $redispatchDelaySeconds,
                );

                return;
            }

            $latestLocation = $candidate->locations()->latest('recorded_at')->first();

            if (! $latestLocation instanceof DeliveryDriverLocation) {
                $this->retryWithoutCandidate(
                    order: $order,
                    reason: self::DRIVER_LOCATION_UNAVAILABLE_NOTE,
                    redispatchOrderId: $redispatchOrderId,
                    redispatchDelaySeconds: $redispatchDelaySeconds,
                );

                return;
            }

            $distanceToPickupKm = round($this->locationService->calculateHaversineDistance(
                (float) $latestLocation->latitude,
                (float) $latestLocation->longitude,
                (float) $order->pickup_latitude,
                (float) $order->pickup_longitude,
            ), 3);

            $attemptNo = ((int) $order->assignmentAttempts()
                ->where('driver_id', $candidate->id)
                ->max('attempt_no')) + 1;

            $offerTimeoutSeconds = (int) config('delivery.dispatch.offer_timeout_seconds', 30);
            $offeredAt = now();
            $expiresAt = $offeredAt->copy()->addSeconds($offerTimeoutSeconds);

            $attempt = DeliveryAssignmentAttempt::query()->create([
                'order_id' => $order->id,
                'driver_id' => $candidate->id,
                'attempt_no' => $attemptNo,
                'status' => DeliveryAssignmentAttemptStatus::Open->value,
                'distance_to_pickup_km' => $distanceToPickupKm,
                'offered_at' => $offeredAt,
                'expires_at' => $expiresAt,
            ]);

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::Dispatching,
                to: DeliveryOrderStatus::Offered,
                note: 'Offer sent to driver',
                actorType: 'delivery_driver',
                actorId: $candidate->id,
                payload: [
                    'attemptId' => $attempt->id,
                    'distanceToPickupKm' => $distanceToPickupKm,
                ],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::Offered->value])->save();

            ExpireAssignmentAttemptJob::dispatch($attempt->id)->delay($expiresAt);

            $attemptId = $attempt->id;
        });

        if ($redispatchOrderId !== null) {
            DispatchDeliveryOrderJob::dispatch($redispatchOrderId)->delay($redispatchDelaySeconds ?? 60);
        }

        if ($attemptId !== null) {
            $attempt = DeliveryAssignmentAttempt::query()
                ->with(['order', 'driver.user'])
                ->find($attemptId);

            if ($attempt instanceof DeliveryAssignmentAttempt) {
                $this->notifications->notifyOfferToDriver($attempt);
            }
        }
    }

    public function expireAttempt(int $attemptId): void
    {
        $shouldRedispatch = false;
        $orderId = null;
        $timedOutAttemptId = null;

        DB::transaction(function () use ($attemptId, &$shouldRedispatch, &$orderId, &$timedOutAttemptId): void {
            $attempt = DeliveryAssignmentAttempt::query()->lockForUpdate()->find($attemptId);

            if (! $attempt instanceof DeliveryAssignmentAttempt) {
                return;
            }

            if ($attempt->status !== DeliveryAssignmentAttemptStatus::Open->value) {
                return;
            }

            if ($attempt->expires_at !== null && $attempt->expires_at->isFuture()) {
                return;
            }

            $order = DeliveryOrder::query()->lockForUpdate()->find($attempt->order_id);

            if (! $order instanceof DeliveryOrder) {
                return;
            }

            $attempt->forceFill([
                'status' => DeliveryAssignmentAttemptStatus::TimedOut->value,
                'responded_at' => now(),
            ])->save();

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::tryFrom((string) $order->status),
                to: DeliveryOrderStatus::Dispatching,
                note: 'Assignment attempt timed out',
                payload: ['attemptId' => $attempt->id],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::Dispatching->value])->save();
            $shouldRedispatch = true;
            $orderId = $order->id;
            $timedOutAttemptId = $attempt->id;
        });

        if ($timedOutAttemptId !== null) {
            $attempt = DeliveryAssignmentAttempt::query()
                ->with(['order', 'driver.user'])
                ->find($timedOutAttemptId);

            if ($attempt instanceof DeliveryAssignmentAttempt) {
                $this->notifications->notifyOfferTimedOut($attempt);
            }
        }

        if ($shouldRedispatch && $orderId !== null) {
            DispatchDeliveryOrderJob::dispatch($orderId);
        }
    }

    public function acceptAttempt(int $attemptId, DeliveryDriver $driver): DeliveryOrder
    {
        $order = DB::transaction(function () use ($attemptId, $driver): DeliveryOrder {
            $attempt = DeliveryAssignmentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($attempt->order_id);

            if ($attempt->status !== DeliveryAssignmentAttemptStatus::Open->value || $attempt->expires_at?->isPast()) {
                throw new RuntimeException('Offer no longer available');
            }

            if ((int) $attempt->driver_id !== (int) $driver->id) {
                throw new RuntimeException('Unauthorized');
            }

            if ($this->driverHasActiveOrder($driver, (int) $order->id)) {
                throw new RuntimeException('Driver already has an active order');
            }

            $attempt->forceFill([
                'status' => DeliveryAssignmentAttemptStatus::Accepted->value,
                'responded_at' => now(),
            ])->save();

            DeliveryAssignmentAttempt::query()
                ->where('order_id', $order->id)
                ->where('id', '!=', $attempt->id)
                ->where('status', DeliveryAssignmentAttemptStatus::Open->value)
                ->update(['status' => DeliveryAssignmentAttemptStatus::Cancelled->value]);

            $order->forceFill([
                'driver_id' => $driver->id,
                'status' => DeliveryOrderStatus::Accepted->value,
                'accepted_at' => now(),
            ])->save();

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::Offered,
                to: DeliveryOrderStatus::Accepted,
                note: 'Driver accepted offer',
                actorType: 'delivery_driver',
                actorId: $driver->id,
                payload: ['attemptId' => $attempt->id],
            );

            $this->sourceSync->sync($order->fresh('source'), DeliveryOrderStatus::Accepted, 'Driver accepted offer');

            $driver->forceFill([
                'availability_status' => DeliveryDriverAvailabilityStatus::Busy->value,
            ])->save();

            return $order->fresh(['company', 'createdBy']);
        });

        $this->notifications->notifyOrderAccepted($order);
        $this->userNotifications->notifyAccepted($order);

        return $order;
    }

    public function rejectAttempt(int $attemptId, DeliveryDriver $driver, string $reason): void
    {
        $orderId = null;

        DB::transaction(function () use ($attemptId, $driver, $reason, &$orderId): void {
            $attempt = DeliveryAssignmentAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if ((int) $attempt->driver_id !== (int) $driver->id) {
                throw new RuntimeException('Unauthorized');
            }

            if ($attempt->status !== DeliveryAssignmentAttemptStatus::Open->value) {
                throw new RuntimeException('Offer no longer available');
            }

            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($attempt->order_id);

            $attempt->forceFill([
                'status' => DeliveryAssignmentAttemptStatus::Rejected->value,
                'reject_reason' => $reason,
                'responded_at' => now(),
            ])->save();

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::tryFrom((string) $order->status),
                to: DeliveryOrderStatus::Dispatching,
                note: 'Driver rejected offer',
                actorType: 'delivery_driver',
                actorId: $driver->id,
                payload: [
                    'attemptId' => $attempt->id,
                    'reason' => $reason,
                ],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::Dispatching->value])->save();
            $orderId = $order->id;
        });

        if ($orderId !== null) {
            DispatchDeliveryOrderJob::dispatch($orderId);
        }
    }

    public function currentOpenAttemptForDriver(DeliveryDriver $driver): ?DeliveryAssignmentAttempt
    {
        return DeliveryAssignmentAttempt::query()
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Open->value)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();
    }

    public function currentActiveOrderForDriver(DeliveryDriver $driver): ?DeliveryOrder
    {
        return DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ])
            ->latest('updated_at')
            ->first();
    }

    private function retryWithoutCandidate(
        DeliveryOrder $order,
        string $reason,
        ?int &$redispatchOrderId,
        ?int &$redispatchDelaySeconds,
    ): void {
        if ($this->shouldStopAfterNoCandidateRetries($order)) {
            $this->deliveryOrderService->markStopped($order, $reason);

            return;
        }

        $this->deliveryOrderService->recordStatusChange(
            order: $order,
            from: DeliveryOrderStatus::Dispatching,
            to: DeliveryOrderStatus::Dispatching,
            note: $reason,
        );

        $redispatchOrderId = (int) $order->id;
        $redispatchDelaySeconds = $this->noCandidateRetryDelaySeconds();
    }

    private function shouldStopAfterNoCandidateRetries(DeliveryOrder $order): bool
    {
        $maxRetries = (int) config('delivery.dispatch.max_no_candidate_retries', 10);

        if ($maxRetries < 0) {
            return false;
        }

        $retryCount = $order->events()
            ->where('to_status', DeliveryOrderStatus::Dispatching->value)
            ->whereIn('note', [
                self::NO_ELIGIBLE_DRIVERS_NOTE,
                self::DRIVER_LOCATION_UNAVAILABLE_NOTE,
            ])
            ->count();

        return $retryCount >= $maxRetries;
    }

    private function noCandidateRetryDelaySeconds(): int
    {
        return max(1, (int) config('delivery.dispatch.no_candidate_retry_seconds', 60));
    }

    private function selectNextCandidate(DeliveryOrder $order): ?DeliveryDriver
    {
        $staleCutoff = now()->subMinutes((int) config('delivery.dispatch.stale_location_minutes', 5));
        $maxRadiusKm = (float) config('delivery.dispatch.max_search_radius_km', 15);

        $excludedDriverIds = $order->assignmentAttempts()
            ->whereIn('status', [
                DeliveryAssignmentAttemptStatus::Rejected->value,
                DeliveryAssignmentAttemptStatus::TimedOut->value,
            ])
            ->pluck('driver_id')
            ->all();

        /** @var Collection<int, DeliveryDriver> $drivers */
        $drivers = DeliveryDriver::query()
            ->where('company_id', $order->company_id)
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->where('availability_status', DeliveryDriverAvailabilityStatus::Available->value)
            ->where('last_seen_at', '>=', $staleCutoff)
            ->when($excludedDriverIds !== [], fn ($query) => $query->whereNotIn('id', $excludedDriverIds))
            ->whereHas('locations', fn ($query) => $query->where('recorded_at', '>=', $staleCutoff))
            ->get();

        $ranked = $drivers
            ->map(function (DeliveryDriver $driver) use ($order): ?array {
                $latestLocation = $driver->locations()->latest('recorded_at')->first();

                if (! $latestLocation instanceof DeliveryDriverLocation) {
                    return null;
                }

                $distanceKm = $this->locationService->calculateHaversineDistance(
                    (float) $latestLocation->latitude,
                    (float) $latestLocation->longitude,
                    (float) $order->pickup_latitude,
                    (float) $order->pickup_longitude,
                );

                return [
                    'driver' => $driver,
                    'distanceKm' => $distanceKm,
                ];
            })
            ->filter()
            ->filter(fn (array $row): bool => $row['distanceKm'] <= $maxRadiusKm)
            ->sortBy('distanceKm')
            ->values();

        $first = $ranked->first();

        return $first['driver'] ?? null;
    }

    private function driverHasActiveOrder(DeliveryDriver $driver, int $excludingOrderId): bool
    {
        return DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->where('id', '!=', $excludingOrderId)
            ->whereIn('status', [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ])
            ->exists();
    }
}
