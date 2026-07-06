<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Support\DeliveryPresentation;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    private const STATUS_ARABIC = [
        OrderStatus::Pending->value => 'قيد الانتظار',
        OrderStatus::Accepted->value => 'مقبول',
        OrderStatus::Preparing->value => 'قيد التحضير',
        OrderStatus::ReadyForPickup->value => 'جاهز للاستلام',
        OrderStatus::PickedUp->value => 'تم الاستلام',
        OrderStatus::Completed->value => 'مكتمل',
        OrderStatus::Cancelled->value => 'ملغي',
    ];

    public function toArray(Request $request): array
    {
        $statusValue = $this->status?->value ?? $this->status;
        $deliverySummary = DeliveryPresentation::merchantSummary($this->resource);
        $deliveryOrder = $this->relationLoaded('deliveryOrder') ? $this->deliveryOrder : null;

        return [
            'id' => $this->id,
            'deliveryOrderId' => $deliveryOrder?->id,
            'userId' => $this->user_id,
            'userAddressId' => $this->user_address_id,
            'restaurantId' => $this->restaurant_id,
            'promoCodeId' => $this->promo_code_id,
            'assignedStaffId' => $this->assigned_staff_id,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'orderNumber' => $this->order_number,
            'status' => $statusValue,
            'statusLabelAr' => $statusValue ? (self::STATUS_ARABIC[$statusValue] ?? $statusValue) : null,
            'orderType' => $this->order_type?->value ?? $this->order_type,
            'pickupMode' => $this->pickup_mode?->value ?? $this->pickup_mode,
            'pickupScheduledFor' => $this->pickup_scheduled_for?->toDateTimeString(),
            'readyForPickupAt' => $this->ready_for_pickup_at?->toDateTimeString(),
            'pickedUpAt' => $this->picked_up_at?->toDateTimeString(),
            'customerPickupConfirmedAt' => $this->customer_pickup_confirmed_at?->toDateTimeString(),
            'subtotal' => $this->subtotal ? (float) $this->subtotal : null,
            'discountAmount' => $this->discount_amount ? (float) $this->discount_amount : null,
            'taxAmount' => $this->tax_amount ? (float) $this->tax_amount : null,
            'serviceFee' => $this->service_fee ? (float) $this->service_fee : null,
            'totalAmount' => $this->total_amount ? (float) $this->total_amount : null,
            'cancellationFeeAmount' => $this->cancellation_fee_amount ? (float) $this->cancellation_fee_amount : null,
            'cancellationPolicySnapshot' => $this->cancellation_policy_snapshot,
            'specialInstructions' => $this->special_instructions,
            'acceptedAt' => $this->accepted_at?->toDateTimeString(),
            'estimatedPreparationMinutes' => $this->estimated_preparation_minutes,
            'kitchenNotes' => $this->kitchen_notes,
            'preparingAt' => $this->preparing_at?->toDateTimeString(),
            'completedAt' => $this->completed_at?->toDateTimeString(),
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'cancellationReasonCode' => $this->cancellation_reason_code,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                'mobile' => $this->user->phone,
                'email' => $this->user->email,
            ]),
            'userAddress' => $this->whenLoaded('userAddress', fn () => $this->userAddress ? [
                'id' => $this->userAddress->id,
                'label' => $this->userAddress->label,
                'mobile' => $this->userAddress->mobile,
                'city' => $this->userAddress->city,
                'neighborhood' => $this->userAddress->neighborhood,
                'street' => $this->userAddress->street,
                'building' => $this->userAddress->building,
                'floor' => $this->userAddress->floor,
                'directions' => $this->userAddress->directions,
                'latitude' => $this->userAddress->latitude !== null ? (float) $this->userAddress->latitude : null,
                'longitude' => $this->userAddress->longitude !== null ? (float) $this->userAddress->longitude : null,
                'isDefault' => (bool) $this->userAddress->is_default,
            ] : null),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
            ]),
            'orderItems' => $this->whenLoaded('orderItems', fn () => $this->orderItems->map(fn ($item) => [
                'id' => $item->id,
                'orderId' => $item->order_id,
                'productId' => $item->product_id,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price ? (float) $item->unit_price : null,
                'totalPrice' => $item->total_price ? (float) $item->total_price : null,
                'specialInstructions' => $item->special_instructions,
                'product' => $item->relationLoaded('product') && $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'primaryImage' => $item->product->getFirstMediaUrl('primary-image'),
                    'imageUrl' => $item->product->getFirstMediaUrl('primary-image') ?: null,
                ] : null,
            ])->values()->all()),
            'orderStatusLogs' => $this->whenLoaded('orderStatusLogs'),
            'deliverySummary' => $deliverySummary,
            'promoCode' => $this->whenLoaded('promoCode'),
            'assignedStaff' => $this->whenLoaded('assignedStaff', fn () => $this->assignedStaff ? [
                'id' => $this->assignedStaff->id,
                'name' => $this->assignedStaff->name,
                'email' => $this->assignedStaff->email,
            ] : null),
            'disputes' => $this->whenLoaded('disputes'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
