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
    private const NO_ELIGIBLE_DRIVERS_NOTE = 'No eligible drivers are currently available.';

    private const DRIVER_POOL_EXHAUSTED_NOTE = 'All eligible drivers rejected or timed out.';

    public function __construct(
        private readonly DriverLocationService $locationService,
        private readonly DeliveryOrderService $deliveryOrderService,
        private readonly DeliveryNotificationService $notifications,
        private readonly DeliverySourceOrderSyncService $sourceSync,
        private readonly DeliveryUserNotificationService $userNotifications,
    ) {}

    public function dispatchByOrderId(int $orderId): void
    {
        $attemptIds = [];
        $redispatchOrderId = null;
        $redispatchDelaySeconds = null;

        DB::transaction(function () use ($orderId, &$attemptIds, &$redispatchOrderId, &$redispatchDelaySeconds): void {
            $order = DeliveryOrder::query()->lockForUpdate()->find($orderId);
            if (! $order instanceof DeliveryOrder || ! in_array($order->status, [DeliveryOrderStatus::SearchingForDriver->value, DeliveryOrderStatus::Dispatching->value], true)) {
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

            $eligibleRows = $this->eligibleDriverRows($order);
            if ($eligibleRows->isEmpty()) {
                $this->retryWithoutCandidate($order, self::NO_ELIGIBLE_DRIVERS_NOTE, $redispatchOrderId, $redispatchDelaySeconds);
                return;
            }

            $wavePlan = $this->nextWavePlan($order, $eligibleRows);
            if ($wavePlan['exhausted']) {
                $this->deliveryOrderService->markStopped($order, self::DRIVER_POOL_EXHAUSTED_NOTE);
                return;
            }

            $this->persistWaveState($order, $wavePlan);
            $candidateRows = $wavePlan['candidates'];
            if ($candidateRows->isEmpty()) {
                $this->recordEmptyWave($order, $wavePlan);
                $redispatchOrderId = (int) $order->id;
                $redispatchDelaySeconds = $this->offerTimeoutSeconds();
                return;
            }

            $offeredAt = now();
            $expiresAt = $offeredAt->copy()->addSeconds($this->offerTimeoutSeconds());
            $createdAttemptIds = [];
            $driverIds = [];
            $candidateTiers = [];
            $largestDistanceKm = null;

            foreach ($candidateRows as $row) {
                $driver = $row['driver'];
                if (! $driver instanceof DeliveryDriver) {
                    continue;
                }

                $attempt = DeliveryAssignmentAttempt::query()->create([
                    'order_id' => $order->id,
                    'driver_id' => $driver->id,
                    'attempt_no' => ((int) $order->assignmentAttempts()->where('driver_id', $driver->id)->max('attempt_no')) + 1,
                    'dispatch_wave' => $wavePlan['wave'],
                    'candidate_tier' => $row['tier'],
                    'status' => DeliveryAssignmentAttemptStatus::Open->value,
                    'distance_to_pickup_km' => $row['distanceKm'] !== null ? round((float) $row['distanceKm'], 3) : null,
                    'offered_at' => $offeredAt,
                    'expires_at' => $expiresAt,
                ]);

                $createdAttemptIds[] = $attempt->id;
                $driverIds[] = $driver->id;
                $candidateTiers[] = $row['tier'];
                if ($row['distanceKm'] !== null) {
                    $largestDistanceKm = max((float) ($largestDistanceKm ?? 0), (float) $row['distanceKm']);
                }
                ExpireAssignmentAttemptJob::dispatch($attempt->id)->delay($expiresAt);
            }

            if ($createdAttemptIds === []) {
                $this->retryWithoutCandidate($order, self::NO_ELIGIBLE_DRIVERS_NOTE, $redispatchOrderId, $redispatchDelaySeconds);
                return;
            }

            $this->deliveryOrderService->recordStatusChange(
                order: $order,
                from: DeliveryOrderStatus::tryFrom((string) $order->status),
                to: DeliveryOrderStatus::Offered,
                note: 'Offers sent to driver pool',
                payload: [
                    'attemptIds' => $createdAttemptIds,
                    'driverIds' => $driverIds,
                    'candidateCount' => count($createdAttemptIds),
                    'candidateTiers' => array_values(array_unique($candidateTiers)),
                    'largestDistanceKm' => $largestDistanceKm !== null ? round($largestDistanceKm, 3) : null,
                    'dispatchWave' => $wavePlan['wave'],
                    'searchRadiusKm' => $wavePlan['radius'],
                    'dispatchPhase' => $wavePlan['phase'],
                ],
            );
            $order->forceFill(['status' => DeliveryOrderStatus::Offered->value])->save();
            $attemptIds = $createdAttemptIds;
        });

        if ($redispatchOrderId !== null) {
            DispatchDeliveryOrderJob::dispatch($redispatchOrderId)->delay($redispatchDelaySeconds ?? 60);
        }
        foreach ($attemptIds as $attemptId) {
            $attempt = DeliveryAssignmentAttempt::query()->with(['order', 'driver.user'])->find($attemptId);
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
            if (! $attempt instanceof DeliveryAssignmentAttempt || $attempt->status !== DeliveryAssignmentAttemptStatus::Open->value || $attempt->expires_at === null || $attempt->expires_at->isFuture()) {
                return;
            }
            $order = DeliveryOrder::query()->lockForUpdate()->find($attempt->order_id);
            if (! $order instanceof DeliveryOrder) {
                return;
            }

            $attempt->forceFill(['status' => DeliveryAssignmentAttemptStatus::TimedOut->value, 'responded_at' => now()])->save();
            $hasOpenAttempts = $order->assignmentAttempts()->where('id', '!=', $attempt->id)->where('status', DeliveryAssignmentAttemptStatus::Open->value)->exists();

            if ($hasOpenAttempts) {
                $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::tryFrom((string) $order->status), DeliveryOrderStatus::Offered, 'Assignment attempt timed out; waiting for other drivers', payload: ['attemptId' => $attempt->id]);
                $timedOutAttemptId = $attempt->id;
                return;
            }

            $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::tryFrom((string) $order->status), DeliveryOrderStatus::SearchingForDriver, 'All driver offers timed out', payload: ['attemptId' => $attempt->id]);
            $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
            $shouldRedispatch = true;
            $orderId = $order->id;
            $timedOutAttemptId = $attempt->id;
        });

        if ($timedOutAttemptId !== null) {
            $attempt = DeliveryAssignmentAttempt::query()->with(['order', 'driver.user'])->find($timedOutAttemptId);
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
        $orderId = DeliveryAssignmentAttempt::query()->whereKey($attemptId)->value('order_id');
        if ($orderId === null) {
            throw new RuntimeException('Offer no longer available');
        }

        $order = DB::transaction(function () use ($attemptId, $driver, $orderId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($orderId);
            $attempt = DeliveryAssignmentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ((int) $attempt->order_id !== (int) $order->id) {
                throw new RuntimeException('Offer no longer available');
            }
            if ($attempt->status !== DeliveryAssignmentAttemptStatus::Open->value || $attempt->expires_at?->isPast()) {
                throw new RuntimeException('Offer no longer available');
            }
            if ($order->driver_id !== null || $order->status !== DeliveryOrderStatus::Offered->value) {
                throw new RuntimeException('Order was already assigned');
            }
            if ((int) $attempt->driver_id !== (int) $driver->id) {
                throw new RuntimeException('Unauthorized');
            }
            if ($this->driverHasActiveOrder($driver, (int) $order->id)) {
                throw new RuntimeException('Driver already has an active order');
            }

            $attempt->forceFill(['status' => DeliveryAssignmentAttemptStatus::Accepted->value, 'responded_at' => now()])->save();
            DeliveryAssignmentAttempt::query()->where('order_id', $order->id)->where('id', '!=', $attempt->id)->where('status', DeliveryAssignmentAttemptStatus::Open->value)->update(['status' => DeliveryAssignmentAttemptStatus::Cancelled->value]);
            $order->forceFill(['driver_id' => $driver->id, 'status' => DeliveryOrderStatus::Accepted->value, 'accepted_at' => now()])->save();
            $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::Offered, DeliveryOrderStatus::Accepted, 'Driver accepted offer', 'delivery_driver', $driver->id, ['attemptId' => $attempt->id]);
            $this->sourceSync->sync($order->fresh('source'), DeliveryOrderStatus::Accepted, 'Driver accepted offer');
            $driver->forceFill(['availability_status' => DeliveryDriverAvailabilityStatus::Busy->value])->save();

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
            $attempt->forceFill(['status' => DeliveryAssignmentAttemptStatus::Rejected->value, 'reject_reason' => $reason, 'responded_at' => now()])->save();
            $hasOpenAttempts = $order->assignmentAttempts()->where('id', '!=', $attempt->id)->where('status', DeliveryAssignmentAttemptStatus::Open->value)->exists();

            if ($hasOpenAttempts) {
                $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::tryFrom((string) $order->status), DeliveryOrderStatus::Offered, 'Driver rejected offer; waiting for other drivers', 'delivery_driver', $driver->id, ['attemptId' => $attempt->id, 'reason' => $reason]);
                return;
            }

            $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::tryFrom((string) $order->status), DeliveryOrderStatus::SearchingForDriver, 'All drivers rejected or timed out', 'delivery_driver', $driver->id, ['attemptId' => $attempt->id, 'reason' => $reason]);
            $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
            $orderId = $order->id;
        });

        if ($orderId !== null) {
            DispatchDeliveryOrderJob::dispatch($orderId);
        }
    }

    public function currentOpenAttemptForDriver(DeliveryDriver $driver): ?DeliveryAssignmentAttempt
    {
        return DeliveryAssignmentAttempt::query()->where('driver_id', $driver->id)->where('status', DeliveryAssignmentAttemptStatus::Open->value)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('created_at')->first();
    }

    public function currentActiveOrderForDriver(DeliveryDriver $driver): ?DeliveryOrder
    {
        return DeliveryOrder::query()->where('driver_id', $driver->id)->whereIn('status', [DeliveryOrderStatus::Accepted->value, DeliveryOrderStatus::InProgress->value, DeliveryOrderStatus::PickedUp->value])->latest('updated_at')->first();
    }

    private function retryWithoutCandidate(DeliveryOrder $order, string $reason, ?int &$redispatchOrderId, ?int &$redispatchDelaySeconds): void
    {
        if ($this->shouldStopAfterNoCandidateRetries($order)) {
            $this->deliveryOrderService->markStopped($order, $reason);
            return;
        }
        $this->deliveryOrderService->recordStatusChange($order, DeliveryOrderStatus::tryFrom((string) $order->status), DeliveryOrderStatus::SearchingForDriver, $reason);
        $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
        $redispatchOrderId = (int) $order->id;
        $redispatchDelaySeconds = $this->noCandidateRetryDelaySeconds();
    }

    private function shouldStopAfterNoCandidateRetries(DeliveryOrder $order): bool
    {
        $maxRetries = (int) config('delivery.dispatch.max_no_candidate_retries', 10);
        if ($maxRetries < 0) {
            return false;
        }
        return $order->events()->whereIn('to_status', [DeliveryOrderStatus::SearchingForDriver->value, DeliveryOrderStatus::Dispatching->value])->where('note', self::NO_ELIGIBLE_DRIVERS_NOTE)->count() >= $maxRetries;
    }

    private function noCandidateRetryDelaySeconds(): int
    {
        return max(1, (int) config('delivery.dispatch.no_candidate_retry_seconds', 60));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eligibleDriverRows(DeliveryOrder $order): Collection
    {
        $staleCutoff = now()->subMinutes((int) config('delivery.dispatch.stale_location_minutes', 5));

        return DeliveryDriver::query()
            ->where('company_id', $order->company_id)
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->where('availability_status', DeliveryDriverAvailabilityStatus::Available->value)
            ->get()
            ->reject(fn (DeliveryDriver $driver): bool => $this->driverHasActiveOrder($driver, (int) $order->id))
            ->map(fn (DeliveryDriver $driver): array => $this->rankDriver($driver, $order, $staleCutoff))
            ->sort($this->rowSorter())
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $eligibleRows
     *  @return array{wave:int,radius:float,phase:string,candidates:Collection<int, array<string, mixed>>,exhausted:bool}
     */
    private function nextWavePlan(DeliveryOrder $order, Collection $eligibleRows): array
    {
        $locatedRows = $eligibleRows->where('tier', 'located')->values();
        $fallbackRows = $eligibleRows->where('tier', 'fallback')->values();
        $currentRadius = (float) ($order->search_radius_km ?? config('delivery.dispatch.initial_search_radius_km', 5));
        $nextWave = ((int) $order->dispatch_wave) + 1;

        if ((int) $order->dispatch_wave === 0) {
            if ($locatedRows->isEmpty() && $fallbackRows->isNotEmpty()) {
                return $this->wavePlan($nextWave, $currentRadius, 'fallback', $eligibleRows);
            }

            return $this->wavePlan($nextWave, $currentRadius, 'radius', $this->withinRadius($locatedRows, $currentRadius));
        }

        $outsideRows = $locatedRows->filter(fn (array $row): bool => (float) $row['distanceKm'] > $currentRadius)->values();
        if ($outsideRows->isNotEmpty()) {
            $nextRadius = (float) $outsideRows->min('distanceKm');

            return $this->wavePlan($nextWave, $nextRadius, 'radius', $this->withinRadius($locatedRows, $nextRadius));
        }

        if ($this->hasUnattemptedFallbackDriver($order, $fallbackRows)) {
            return $this->wavePlan($nextWave, $currentRadius, 'fallback', $eligibleRows);
        }

        return $this->wavePlan($nextWave, $currentRadius, (string) $order->dispatch_phase, collect(), true);
    }

    /** @param Collection<int, array<string, mixed>> $candidates
     *  @return array{wave:int,radius:float,phase:string,candidates:Collection<int, array<string, mixed>>,exhausted:bool}
     */
    private function wavePlan(int $wave, float $radius, string $phase, Collection $candidates, bool $exhausted = false): array
    {
        return compact('wave', 'radius', 'phase', 'candidates', 'exhausted');
    }

    /** @param Collection<int, array<string, mixed>> $locatedRows */
    private function withinRadius(Collection $locatedRows, float $radius): Collection
    {
        return $locatedRows->filter(fn (array $row): bool => (float) $row['distanceKm'] <= $radius)->values();
    }

    /** @param Collection<int, array<string, mixed>> $fallbackRows */
    private function hasUnattemptedFallbackDriver(DeliveryOrder $order, Collection $fallbackRows): bool
    {
        if ($fallbackRows->isEmpty()) {
            return false;
        }

        $attemptedDriverIds = $order->assignmentAttempts()->where('candidate_tier', 'fallback')->pluck('driver_id');

        return $fallbackRows->contains(fn (array $row): bool => ! $attemptedDriverIds->contains($row['driver']->id));
    }

    /** @param array{wave:int,radius:float,phase:string,candidates:Collection<int, array<string, mixed>>,exhausted:bool} $wavePlan */
    private function persistWaveState(DeliveryOrder $order, array $wavePlan): void
    {
        $order->forceFill([
            'dispatch_wave' => $wavePlan['wave'],
            'search_radius_km' => $wavePlan['radius'],
            'dispatch_phase' => $wavePlan['phase'],
        ])->save();
    }

    /** @param array{wave:int,radius:float,phase:string,candidates:Collection<int, array<string, mixed>>,exhausted:bool} $wavePlan */
    private function recordEmptyWave(DeliveryOrder $order, array $wavePlan): void
    {
        $this->deliveryOrderService->recordStatusChange(
            $order,
            DeliveryOrderStatus::tryFrom((string) $order->status),
            DeliveryOrderStatus::SearchingForDriver,
            'No drivers in current radius; expanding search.',
            payload: [
                'dispatchWave' => $wavePlan['wave'],
                'searchRadiusKm' => $wavePlan['radius'],
            ],
        );
        $order->forceFill(['status' => DeliveryOrderStatus::SearchingForDriver->value])->save();
    }

    private function offerTimeoutSeconds(): int
    {
        return max(1, (int) config('delivery.dispatch.offer_timeout_seconds', 60));
    }

    private function rankDriver(DeliveryDriver $driver, DeliveryOrder $order, mixed $staleCutoff): array
    {
        $location = $driver->locations()->latest('recorded_at')->first();
        $distanceKm = $location instanceof DeliveryDriverLocation ? $this->locationService->calculateHaversineDistance((float) $location->latitude, (float) $location->longitude, (float) $order->pickup_latitude, (float) $order->pickup_longitude) : null;
        $hasFreshLocation = $driver->last_seen_at !== null && $driver->last_seen_at->greaterThanOrEqualTo($staleCutoff) && $location instanceof DeliveryDriverLocation && $location->recorded_at !== null && $location->recorded_at->greaterThanOrEqualTo($staleCutoff) && $distanceKm !== null;

        return ['driver' => $driver, 'tier' => $hasFreshLocation ? 'located' : 'fallback', 'tierRank' => $hasFreshLocation ? 0 : 1, 'distanceKm' => $distanceKm, 'lastSeenAt' => $driver->last_seen_at, 'trustScore' => (int) $driver->trust_score, 'openDisputesCount' => (int) $driver->open_disputes_count];
    }

    private function rowSorter(): callable
    {
        return static fn (array $a, array $b): int => ($a['tierRank'] <=> $b['tierRank']) ?: (($a['distanceKm'] ?? PHP_FLOAT_MAX) <=> ($b['distanceKm'] ?? PHP_FLOAT_MAX)) ?: (($b['lastSeenAt']?->getTimestamp() ?? 0) <=> ($a['lastSeenAt']?->getTimestamp() ?? 0)) ?: ($b['trustScore'] <=> $a['trustScore']) ?: ($a['openDisputesCount'] <=> $b['openDisputesCount']);
    }

    private function driverHasActiveOrder(DeliveryDriver $driver, int $excludingOrderId): bool
    {
        return DeliveryOrder::query()->where('driver_id', $driver->id)->where('id', '!=', $excludingOrderId)->whereIn('status', [DeliveryOrderStatus::Accepted->value, DeliveryOrderStatus::InProgress->value, DeliveryOrderStatus::PickedUp->value])->exists();
    }
}
