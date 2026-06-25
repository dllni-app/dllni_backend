<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\Delivery\Support\DeliveryPresentation;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

/**
 * @mixin SmOrder
 */
final class SmOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deliverySummary = DeliveryPresentation::merchantSummary($this->resource);
        $orderDetails = $this->orderDetailsPayload();

        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'customer' => UserResource::make($this->whenLoaded('customer')),
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'couponId' => $this->coupon_id,
            'coupon' => SmCouponResource::make($this->whenLoaded('coupon')),
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'orderNumber' => $this->order_number,
            'status' => $this->status?->value,
            'pickupMode' => $this->pickup_mode?->value,
            'pickupScheduledFor' => $this->pickup_scheduled_for?->toDateTimeString(),
            'readyForPickupAt' => $this->ready_for_pickup_at?->toDateTimeString(),
            'pickedUpAt' => $this->picked_up_at?->toDateTimeString(),
            'customerPickupConfirmedAt' => $this->customer_pickup_confirmed_at?->toDateTimeString(),
            'subtotal' => $this->subtotal,
            'discountAmount' => $this->discount_amount,
            'serviceFee' => $this->service_fee,
            'totalAmount' => $this->total_amount,
            'cancellationFeeAmount' => $this->cancellation_fee_amount,
            'cancellationPolicySnapshot' => $this->cancellation_policy_snapshot,
            'specialInstructions' => $this->special_instructions,
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'deliverySummary' => $deliverySummary,
            'orderDetails' => $orderDetails,
            'order_details' => $orderDetails,
            'items' => SmOrderItemResource::collection($this->whenLoaded('items')),
            'statusLogs' => SmOrderStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'disputes' => SmOrderDisputeResource::collection($this->whenLoaded('disputes')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDetailsPayload(): array
    {
        $internalStatus = $this->status?->value;
        $currentStatus = self::presentationStatus($internalStatus);
        $statusStartedAt = $this->statusStartedAt($internalStatus);
        $deliveredAt = $this->deliveredAt();
        $statusElapsedMinutes = $statusStartedAt ? max(0, (int) $statusStartedAt->diffInMinutes(now())) : null;
        $deliveryDurationMinutes = ($statusStartedAt && $deliveredAt)
            ? max(0, (int) $statusStartedAt->diffInMinutes($deliveredAt))
            : null;

        return [
            'current_status' => $currentStatus,
            'current_status_label' => self::statusLabelAr($currentStatus),
            'status_started_at' => self::isoDate($statusStartedAt),
            'status_elapsed_minutes' => $statusElapsedMinutes,
            'status_elapsed_text' => self::minutesText($statusElapsedMinutes),
            'expected_delivery_at' => self::isoDate($this->pickup_scheduled_for),
            'expected_delivery_time' => self::timeText($this->pickup_scheduled_for),
            'delivered_at' => self::isoDate($deliveredAt),
            'delivered_time' => self::timeText($deliveredAt),
            'delivery_duration_minutes' => $deliveryDurationMinutes,
            'delivery_duration_text' => self::minutesText($deliveryDurationMinutes),
        ];
    }

    private function statusStartedAt(?string $internalStatus): ?Carbon
    {
        if ($internalStatus !== null && $this->resource->relationLoaded('statusLogs')) {
            $statusLog = $this->resource->statusLogs
                ->where('to_status', $internalStatus)
                ->sortByDesc('created_at')
                ->first();

            if ($statusLog?->created_at !== null) {
                return Carbon::parse($statusLog->created_at);
            }
        }

        if ($internalStatus !== null) {
            $statusLog = $this->resource->statusLogs()
                ->where('to_status', $internalStatus)
                ->latest('created_at')
                ->first();

            if ($statusLog?->created_at !== null) {
                return Carbon::parse($statusLog->created_at);
            }
        }

        return $this->created_at ? Carbon::parse($this->created_at) : null;
    }

    private function deliveredAt(): ?Carbon
    {
        if ($this->customer_pickup_confirmed_at !== null) {
            return Carbon::parse($this->customer_pickup_confirmed_at);
        }

        $completedStatus = SmOrderStatus::Completed->value;

        if ($this->resource->relationLoaded('statusLogs')) {
            $statusLog = $this->resource->statusLogs
                ->where('to_status', $completedStatus)
                ->sortByDesc('created_at')
                ->first();

            if ($statusLog?->created_at !== null) {
                return Carbon::parse($statusLog->created_at);
            }
        }

        $statusLog = $this->resource->statusLogs()
            ->where('to_status', $completedStatus)
            ->latest('created_at')
            ->first();

        return $statusLog?->created_at ? Carbon::parse($statusLog->created_at) : null;
    }

    private static function presentationStatus(?string $internalStatus): ?string
    {
        return match ($internalStatus) {
            SmOrderStatus::PickedUp->value => 'out_for_delivery',
            SmOrderStatus::Completed->value => 'delivered',
            default => $internalStatus,
        };
    }

    private static function statusLabelAr(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return [
            SmOrderStatus::Pending->value => 'بانتظار القبول',
            SmOrderStatus::Accepted->value => 'تم قبول الطلب',
            SmOrderStatus::Preparing->value => 'قيد التحضير',
            SmOrderStatus::ReadyForPickup->value => 'جاهز للاستلام',
            SmOrderStatus::PickedUp->value => 'قيد التسليم',
            SmOrderStatus::Completed->value => 'تم التسليم',
            SmOrderStatus::Cancelled->value => 'ملغي',
            'out_for_delivery' => 'قيد التسليم',
            'delivered' => 'تم التسليم',
        ][$status] ?? $status;
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->toJSON();
    }

    private static function timeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse($value)->timezone(config('app.timezone'));
        $suffix = $date->format('A') === 'AM' ? 'ص' : 'م';

        return $date->format('g:i').' '.$suffix;
    }

    private static function minutesText(?int $minutes): ?string
    {
        return $minutes === null ? null : $minutes.' دقيقة';
    }
}
