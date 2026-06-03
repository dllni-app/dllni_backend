<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Delivery\Support\DeliveryPresentation;

/**
 * @mixin SmOrder
 */
final class SmOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deliverySummary = DeliveryPresentation::merchantSummary($this->resource);

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
            'items' => SmOrderItemResource::collection($this->whenLoaded('items')),
            'statusLogs' => SmOrderStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'disputes' => SmOrderDisputeResource::collection($this->whenLoaded('disputes')),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
