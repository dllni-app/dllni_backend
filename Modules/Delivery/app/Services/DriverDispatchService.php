<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

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
    private const NO_ELIGIBLE_DRIVERS_NOTE = 'No eligible drivers available; keeping order in driver search.';

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

            if (! in_array($order->status, [DeliveryOrderStatus::SearchingForDriver->value, DeliveryOrderStatus::Dispatching->value], true)) {
                return;
            }

            if ($order->assignmentAttempts()->where('status', DeliveryAssignmentAttemptStatus::Open->value)->exists()) {
                $order->forceFill(['status' => DeliveryOrderStatus::Offered->value])->save();

                return;
            }

            $order->loadMissing('company');

            if ($order->company?->is_suspended) {
                $this->deliveryOrderService->markStopped($order, 'Company is suspended.');

                return;
            }

            $candidateRow = $this->selectNextCandidate($order);

            if ($candidateRow === null) {
                $this->retryWithoutCandidate($order, self::NO_ELIGIBLE_DRIVERS_NOTE, $redispatchOrderId, $redispatchDelaySeconds);

                return;
            }

            /** @var DeliveryDriver $candidate */
            $candidate = $candidateRow['driver'];

            $attemptNo = ((int) $order->assignmentAttempts()
                ->where('driver_id', $candidate->id)
                ->max('attempt_no')) + 1;

            $offeredAt = now();
            $expiresAt = $this->offerExpiresAt((string) $candidateRow['tier'], $offeredAt);

            $attempt = DeliveryAssignmentAttempt::query()->create([
                'order_id' => $order->id,
                'driver_id' => $candidate->id,
                'attempt_no' => $attemptNo,
                'status' => DeliveryAssignmentAttemptStatus::Open->value,
                'distance_to_pickup_km' => $candidateRow['distanceKm'] !== null ? round((float) $candidateRow['distanceKm'], 3) : null,
                'offered_at' => $offeredAt,
                'expires_at' => $expiresAt,
            ]);

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::tryFrom((string) $order->status),
                to: DeliveryOrderStatus::Offered,
                note: 'Offer sent to ranked driver candidate',
                actorType: 'delivery_driver',
                actorId: $candidate->id,
                payload: [
                    'attemptId' => $attempt->id,
                    'distanceToPickupKm' => $candidateRow['distanceKm'],
                    'candidateTier' => $candidateRow['tier'],
                ],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::Offered->value])->save();

            if ($expiresAt !== null) {
                ExpireAssignmentAttemptJob::dispatch($attempt->id)->delay($expiresAt);
            }

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

            if ($attempt->expires_at === null || $attempt->expires_at->isFuture()) {
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
                to: DeliveryOrderStatus::SearchingForDriver,
                note: 'Assignment attempt timed out; continuing search',
                payload: ['attemptId' => $attempt->id],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
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
                to: DeliveryOrderStatus::SearchingForDriver,
                note: 'Driver rejected offer; continuing search',
                actorType: 'delivery_driver',
                actorId: $driver->id,
                payload: [
                    'attemptId' => $attempt->id,
                    'reason' => $reason,
                ],
            );

            $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
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
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
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
        if ((bool) config('delivery.dispatch.stop_when_no_driver', false)) {
            $this->deliveryOrderService->markStopped($order, $reason);

            return;
        }

        $this->deliveryOrderService->recordStatusChange(
            order: $order,
            from: DeliveryOrderStatus::tryFrom((string) $order->status),
            to: DeliveryOrderStatus::SearchingForDriver,
            note: $reason,
        );

        $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
        $redispatchOrderId = (int) $order->id;
        $redispatchDelaySeconds = max(1, (int) config('delivery.dispatch.no_candidate_retry_seconds', 60));
    }

    private function selectNextCandidate(DeliveryOrder $order): ?array
    {
        $staleCutoff = now()->subMinutes((int) config('delivery.dispatch.stale_location_minutes', 5));
        $maxRadiusKm = (float) config('delivery.dispatch.max_search_radius_km', 15);

        $excludedDriverIds = $order->assignmentAttempts()
            ->whereIn('status', [
                DeliveryAssignmentAttemptStatus::Rejected->value,
                DeliveryAssignmentAttemptStatus::TimedOut->value,
                DeliveryAssignmentAttemptStatus::Cancelled->value,
            ])
            ->pluck('driver_id')
            ->all();

        $rows = DeliveryDriver::query()
            ->where('company_id', $order->company_id)
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->when($excludedDriverIds !== [], fn ($query) => $query->whereNotIn('id', $excludedDriverIds))
            ->get()
            ->reject(fn (DeliveryDriver $driver): bool => $this->driverHasActiveOrder($driver, (int) $order->id))
            ->map(fn (DeliveryDriver $driver): array => $this->rankDriver($driver, $order, $staleCutoff, $maxRadiusKm))
            ->sort($this->rowSorter())
            ->values();

        $online = $rows->first(fn (array $row): bool => $row['tier'] === 'online');
        if ($online !== null) {
            return $online;
        }

        $fallback = $rows->first();
        if ($fallback !== null && (bool) config('delivery.dispatch.include_offline_fallback', true)) {
            return $fallback;
        }

        return null;
    }

    private function rankDriver(DeliveryDriver $driver, DeliveryOrder $order, \Illuminate\Support\Carbon $staleCutoff, float $maxRadiusKm): array
    {
        $latestLocation = $driver->locations()->latest('recorded_at')->first();
        $hasFreshSeen = $driver->last_seen_at !== null && $driver->last_seen_at->greaterThanOrEqualTo($staleCutoff);
        $hasFreshLocation = $latestLocation instanceof DeliveryDriverLocation && $latestLocation->recorded_at !== null && $latestLocation->recorded_at->greaterThanOrEqualTo($staleCutoff);
        $distanceKm = null;

        if ($latestLocation instanceof DeliveryDriverLocation) {
            $distanceKm = $this->locationService->calculateHaversineDistance(
                (float) $latestLocation->latitude,
                (float) $latestLocation->longitude,
                (float) $order->pickup_latitude,
                (float) $order->pickup_longitude,
            );
        }

        $isAvailable = $driver->availability_status === DeliveryDriverAvailabilityStatus::Available->value;
        $isOnlineMatch = $isAvailable && $hasFreshSeen && $hasFreshLocation && $distanceKm !== null && $distanceKm <= $maxRadiusKm;

        return [
            'driver' => $driver,
            'tier' => $isOnlineMatch ? 'online' : 'fallback',
            'tierRank' => $isOnlineMatch ? 0 : 1,
            'distanceKm' => $distanceKm,
            'lastSeenAt' => $driver->last_seen_at,
            'trustScore' => (int) $driver->trust_score,
            'openDisputesCount' => (int) $driver->open_disputes_count,
        ];
    }

    private function rowSorter(): callable
    {
        return static function (array $a, array $b): int {
            $tierCompare = $a['tierRank'] <=> $b['tierRank'];
            if ($tierCompare !== 0) {
                return $tierCompare;
            }

            $distanceA = $a['distanceKm'] ?? PHP_FLOAT_MAX;
            $distanceB = $b['distanceKm'] ?? PHP_FLOAT_MAX;
            $distanceCompare = $distanceA <=> $distanceB;
            if ($distanceCompare !== 0) {
                return $distanceCompare;
            }

            $lastSeenA = $a['lastSeenAt']?->getTimestamp() ?? 0;
            $lastSeenB = $b['lastSeenAt']?->getTimestamp() ?? 0;
            $lastSeenCompare = $lastSeenB <=> $lastSeenA;
            if ($lastSeenCompare !== 0) {
                return $lastSeenCompare;
            }

            $trustCompare = $b['trustScore'] <=> $a['trustScore'];
            if ($trustCompare !== 0) {
                return $trustCompare;
            }

            return $a['openDisputesCount'] <=> $b['openDisputesCount'];
        };
    }

    private function offerExpiresAt(string $tier, \Illuminate\Support\Carbon $offeredAt): ?\Illuminate\Support\Carbon
    {
        $seconds = $tier === 'fallback'
            ? (int) config('delivery.dispatch.offline_offer_timeout_seconds', 0)
            : (int) config('delivery.dispatch.offer_timeout_seconds', 30);

        if ($seconds <= 0) {
            return null;
        }

        return $offeredAt->copy()->addSeconds($seconds);
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
