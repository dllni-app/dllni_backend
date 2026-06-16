<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\BookingStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Models\DeliveryOrderEvent;

final class DeliveryOrderService
{
    public function __construct(
        private readonly DeliveryPricingService $pricingService,
        private readonly FinancialLedgerService $ledgerService,
        private readonly FinancialSuspensionService $suspensionService,
        private readonly DeliveryNotificationService $notifications,
    ) {}

    /**
     * @param  array{
     *     customerName: string,
     *     customerPhone?: string|null,
     *     customerNotes?: string|null,
     *     pickupAddress: string,
     *     pickupLatitude: float,
     *     pickupLongitude: float,
     *     dropoffAddress: string,
     *     dropoffLatitude: float,
     *     dropoffLongitude: float,
     *     currency?: string|null,
     * }  $payload
     */
    public function create(DeliveryCompany $company, array $payload, ?int $createdByUserId = null): DeliveryOrder
    {
        if ($company->is_suspended) {
            throw new InvalidArgumentException('Company is suspended and cannot create orders.');
        }

        $pricing = $this->pricingService->calculate(
            pickupLatitude: (float) $payload['pickupLatitude'],
            pickupLongitude: (float) $payload['pickupLongitude'],
            dropoffLatitude: (float) $payload['dropoffLatitude'],
            dropoffLongitude: (float) $payload['dropoffLongitude'],
            currency: $payload['currency'] ?? null,
        );

        $order = DB::transaction(function () use ($company, $payload, $createdByUserId, $pricing): DeliveryOrder {
            $order = DeliveryOrder::query()->create([
                'company_id' => $company->id,
                'order_number' => $this->generateOrderNumber(),
                'customer_name' => $payload['customerName'],
                'customer_phone' => $payload['customerPhone'] ?? null,
                'customer_notes' => $payload['customerNotes'] ?? null,
                'pickup_address' => $payload['pickupAddress'],
                'pickup_latitude' => $payload['pickupLatitude'],
                'pickup_longitude' => $payload['pickupLongitude'],
                'dropoff_address' => $payload['dropoffAddress'],
                'dropoff_latitude' => $payload['dropoffLatitude'],
                'dropoff_longitude' => $payload['dropoffLongitude'],
                'distance_km' => $pricing['distanceKm'],
                'delivery_fee' => $pricing['deliveryFee'],
                'currency' => $pricing['currency'],
                'status' => DeliveryOrderStatus::New->value,
                'created_by_user_id' => $createdByUserId,
            ]);

            $this->recordStatusChange(
                order: $order,
                from: null,
                to: DeliveryOrderStatus::New,
                note: 'Order created',
            );

            $this->applyStatus($order, DeliveryOrderStatus::Dispatching, [
                'note' => 'Pricing calculated and dispatch queued',
            ]);

            return $order->fresh();
        });

        DispatchDeliveryOrderJob::dispatch($order->id);

        return $order;
    }

    public function retryDispatch(DeliveryOrder $order): DeliveryOrder
    {
        $order = DB::transaction(function () use ($order): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $currentStatus = DeliveryOrderStatus::tryFrom((string) $order->status);

            if (! in_array($currentStatus, [DeliveryOrderStatus::Stopped, DeliveryOrderStatus::Dispatching], true)) {
                throw new InvalidArgumentException('Order cannot be retried from the current status.');
            }

            $order->assignmentAttempts()
                ->whereIn('status', ['open', 'rejected', 'timed_out', 'cancelled'])
                ->delete();

            $from = DeliveryOrderStatus::tryFrom((string) $order->status);
            $order->forceFill([
                'status' => DeliveryOrderStatus::Dispatching->value,
                'stopped_at' => null,
                'stop_reason' => null,
            ])->save();

            $this->recordStatusChange(
                order: $order,
                from: $from,
                to: DeliveryOrderStatus::Dispatching,
                note: 'Dispatch retry requested',
            );

            return $order->fresh();
        });

        DispatchDeliveryOrderJob::dispatch($order->id);

        return $order;
    }

    public function start(DeliveryOrder $order, int $driverId): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $driverId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertAssignedDriver($order, $driverId);
            $this->assertCurrentStatus($order, DeliveryOrderStatus::Accepted);

            $this->applyStatus($order, DeliveryOrderStatus::InProgress, [
                'timestampColumn' => 'started_at',
                'note' => 'Driver started delivery',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company', 'driver.user']);
            $this->notifications->notifyOrderStarted($order);

            return $order;
        });
    }

    public function pickup(DeliveryOrder $order, int $driverId): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $driverId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertAssignedDriver($order, $driverId);
            $this->assertCurrentStatus($order, DeliveryOrderStatus::InProgress);

            $this->applyStatus($order, DeliveryOrderStatus::PickedUp, [
                'timestampColumn' => 'picked_up_at',
                'note' => 'Order picked up from sender',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company', 'driver.user']);
            $this->notifications->notifyOrderPickedUp($order);

            return $order;
        });
    }

    public function deliver(DeliveryOrder $order, int $driverId): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $driverId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertAssignedDriver($order, $driverId);
            $this->assertCurrentStatus($order, DeliveryOrderStatus::PickedUp);

            $this->applyStatus($order, DeliveryOrderStatus::Delivered, [
                'timestampColumn' => 'delivered_at',
                'note' => 'Order delivered to recipient',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            DeliveryDriver::query()
                ->where('id', $driverId)
                ->update(['availability_status' => DeliveryDriverAvailabilityStatus::Available->value]);

            $order = $order->fresh(['company', 'driver.user']);
            $this->notifications->notifyOrderDelivered($order);

            $this->complete($order, $driverId);

            return $order->fresh();
        });
    }

    public function complete(DeliveryOrder $order, ?int $driverId = null): DeliveryOrder
    {
        if ($order->status === DeliveryOrderStatus::Completed->value) {
            return $order;
        }

        if ($order->status !== DeliveryOrderStatus::Delivered->value) {
            throw new InvalidArgumentException('Order must be delivered before it can be completed.');
        }

        return DB::transaction(function () use ($order, $driverId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === DeliveryOrderStatus::Completed->value) {
                return $order;
            }

            $this->applyStatus($order, DeliveryOrderStatus::Completed, [
                'timestampColumn' => 'completed_at',
                'note' => 'Order completed',
                'actorType' => $driverId !== null ? 'delivery_driver' : null,
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company']);
            $transaction = $this->ledgerService->recordOrderFeeDebit($order);

            if ($transaction !== null) {
                $account = $this->ledgerService->accountForCompany($order->company, $order->currency);
                $this->suspensionService->evaluateCompanyAccount($account->fresh(), $order->company);
            }

            $this->notifications->notifyOrderCompleted($order);

            return $order->fresh();
        });
    }

    public function cancel(DeliveryOrder $order, string $reason, ?int $cancelledByUserId = null): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $reason, $cancelledByUserId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $currentStatus = DeliveryOrderStatus::tryFrom((string) $order->status);

            if (in_array($currentStatus, [
                DeliveryOrderStatus::Delivered,
                DeliveryOrderStatus::Completed,
                DeliveryOrderStatus::Cancelled,
            ], true)) {
                throw new InvalidArgumentException('Order cannot be cancelled from the current status.');
            }

            $order->assignmentAttempts()
                ->where('status', 'open')
                ->update(['status' => 'cancelled']);

            if ($order->driver_id !== null) {
                DeliveryDriver::query()
                    ->where('id', $order->driver_id)
                    ->where('availability_status', DeliveryDriverAvailabilityStatus::Busy->value)
                    ->update(['availability_status' => DeliveryDriverAvailabilityStatus::Available->value]);
            }

            $this->applyStatus($order, DeliveryOrderStatus::Cancelled, [
                'timestampColumn' => 'cancelled_at',
                'note' => $reason,
                'extraAttributes' => ['cancel_reason' => $reason],
                'actorType' => 'user',
                'actorId' => $cancelledByUserId,
            ]);

            return $order->fresh();
        });
    }

    public function markStopped(DeliveryOrder $order, string $reason): DeliveryOrder
    {
        $wasStopped = false;

        $order = DB::transaction(function () use ($order, $reason, &$wasStopped): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $currentStatus = DeliveryOrderStatus::tryFrom((string) $order->status);

            if (in_array($currentStatus, [
                DeliveryOrderStatus::Delivered,
                DeliveryOrderStatus::Completed,
                DeliveryOrderStatus::Cancelled,
                DeliveryOrderStatus::Stopped,
            ], true)) {
                return $order;
            }

            $order->assignmentAttempts()
                ->where('status', 'open')
                ->update(['status' => 'cancelled']);

            $this->applyStatus($order, DeliveryOrderStatus::Stopped, [
                'timestampColumn' => 'stopped_at',
                'note' => $reason,
                'extraAttributes' => ['stop_reason' => $reason],
            ]);

            $wasStopped = true;

            return $order->fresh(['company']);
        });

        if ($wasStopped) {
            $this->notifications->notifyOrderStopped($order, $reason);
        }

        return $order;
    }

    public function recordStatusChange(
        DeliveryOrder $order,
        ?DeliveryOrderStatus $from,
        DeliveryOrderStatus $to,
        ?string $note = null,
        ?string $actorType = null,
        ?int $actorId = null,
        ?array $payload = null,
    ): void {
        DeliveryOrderEvent::query()->create([
            'order_id' => $order->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'note' => $note,
            'payload' => $payload,
        ]);

        BookingStatusLog::query()->create([
            'booking_id' => $order->id,
            'booking_type' => 'delivery_order',
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'note' => $note,
        ]);
    }

    /**
     * @param  array{
     *     note?: string|null,
     *     timestampColumn?: string|null,
     *     actorType?: string|null,
     *     actorId?: int|null,
     *     extraAttributes?: array<string, mixed>|null,
     *     payload?: array<string, mixed>|null,
     * }  $context
     */
    private function applyStatus(DeliveryOrder $order, DeliveryOrderStatus $to, array $context = []): void
    {
        $from = DeliveryOrderStatus::tryFrom((string) $order->status);

        $attributes = ['status' => $to->value];

        if (! empty($context['timestampColumn'])) {
            $attributes[(string) $context['timestampColumn']] = now();
        }

        if (! empty($context['extraAttributes']) && is_array($context['extraAttributes'])) {
            $attributes = array_merge($attributes, $context['extraAttributes']);
        }

        $order->forceFill($attributes)->save();

        $this->recordStatusChange(
            order: $order,
            from: $from,
            to: $to,
            note: $context['note'] ?? null,
            actorType: $context['actorType'] ?? null,
            actorId: $context['actorId'] ?? null,
            payload: $context['payload'] ?? null,
        );
    }

    private function assertAssignedDriver(DeliveryOrder $order, int $driverId): void
    {
        if ((int) $order->driver_id !== $driverId) {
            throw new InvalidArgumentException('This order is not assigned to the current driver.');
        }
    }

    private function assertCurrentStatus(DeliveryOrder $order, DeliveryOrderStatus $expected): void
    {
        if ($order->status !== $expected->value) {
            throw new InvalidArgumentException("Invalid status transition from {$order->status} to {$expected->value}.");
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $value = 'DEL-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999);
        } while (DeliveryOrder::query()->where('order_number', $value)->exists());

        return $value;
    }
}
