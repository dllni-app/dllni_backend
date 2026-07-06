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
        private readonly DeliverySourceOrderSyncService $sourceSync,
        private readonly DeliveryUserNotificationService $userNotifications,
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
     *     sourceType?: string|null,
     *     sourceId?: int|null,
     * }  $payload
     */
    public function create(DeliveryCompany $company, array $payload, ?int $createdByUserId = null): DeliveryOrder
    {
        if ($company->is_suspended) {
            throw new InvalidArgumentException('الشركة موقوفة ولا يمكنها إنشاء طلبات.');
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
                'source_type' => $payload['sourceType'] ?? null,
                'source_id' => $payload['sourceId'] ?? null,
            ]);

            $this->recordStatusChange(
                order: $order,
                from: null,
                to: DeliveryOrderStatus::New,
                note: 'تم إنشاء الطلب',
            );

            $this->applyStatus($order, DeliveryOrderStatus::Dispatching, [
                'note' => 'تم احتساب السعر وإضافة الطلب إلى قائمة التوزيع',
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
                throw new InvalidArgumentException('لا يمكن إعادة محاولة توزيع الطلب من حالته الحالية.');
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
                note: 'تم طلب إعادة التوزيع',
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
                'note' => 'بدأ السائق عملية التوصيل',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company', 'driver.user', 'createdBy']);
            $this->notifications->notifyOrderStarted($order);
            $this->userNotifications->notifyStarted($order);

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
                'note' => 'تم استلام الطلب من المرسل',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company', 'driver.user', 'createdBy']);
            $this->notifications->notifyOrderPickedUp($order);
            $this->userNotifications->notifyPickedUp($order);

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
                'note' => 'تم تسليم الطلب إلى المستلم',
                'actorType' => 'delivery_driver',
                'actorId' => $driverId,
            ]);

            DeliveryDriver::query()
                ->where('id', $driverId)
                ->update(['availability_status' => DeliveryDriverAvailabilityStatus::Available->value]);

            $order = $order->fresh(['company', 'driver.user', 'createdBy']);
            $this->notifications->notifyOrderDelivered($order);
            $this->userNotifications->notifyDelivered($order);

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
            throw new InvalidArgumentException('يجب تسليم الطلب قبل إكماله.');
        }

        return DB::transaction(function () use ($order, $driverId): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === DeliveryOrderStatus::Completed->value) {
                return $order;
            }

            $this->applyStatus($order, DeliveryOrderStatus::Completed, [
                'timestampColumn' => 'completed_at',
                'note' => 'تم إكمال الطلب',
                'actorType' => $driverId !== null ? 'delivery_driver' : null,
                'actorId' => $driverId,
            ]);

            $order = $order->fresh(['company', 'createdBy']);
            $transaction = $this->ledgerService->recordOrderFeeDebit($order);

            if ($transaction !== null) {
                $account = $this->ledgerService->accountForCompany($order->company, $order->currency);
                $this->suspensionService->evaluateCompanyAccount($account->fresh(), $order->company);
            }

            $this->notifications->notifyOrderCompleted($order);
            $this->userNotifications->notifyCompleted($order);

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
                throw new InvalidArgumentException('لا يمكن إلغاء الطلب من حالته الحالية.');
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

            $order = $order->fresh(['createdBy']);
            $this->userNotifications->notifyCancelled($order, $reason);

            return $order;
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

            return $order->fresh(['company', 'createdBy']);
        });

        if ($wasStopped) {
            $this->notifications->notifyOrderStopped($order, $reason);
            $this->userNotifications->notifyStopped($order, $reason);
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

        $this->sourceSync->sync($order->fresh('source'), $to, $context['note'] ?? null);
    }

    private function assertAssignedDriver(DeliveryOrder $order, int $driverId): void
    {
        if ((int) $order->driver_id !== $driverId) {
            throw new InvalidArgumentException('هذا الطلب غير مسند إلى السائق الحالي.');
        }
    }

    private function assertCurrentStatus(DeliveryOrder $order, DeliveryOrderStatus $expected): void
    {
        if ($order->status !== $expected->value) {
            throw new InvalidArgumentException("انتقال حالة غير صالح من {$order->status} إلى {$expected->value}.");
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
