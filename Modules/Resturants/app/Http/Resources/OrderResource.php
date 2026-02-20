<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Order;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'restaurantId' => $this->restaurant_id,
            'promoCodeId' => $this->promo_code_id,
            'assignedStaffId' => $this->assigned_staff_id,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'orderNumber' => $this->order_number,
            'status' => $this->status?->value ?? $this->status,
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
            'preparingAt' => $this->preparing_at?->toDateTimeString(),
            'completedAt' => $this->completed_at?->toDateTimeString(),
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
            ]),
            'orderItems' => $this->whenLoaded('orderItems'),
            'orderStatusLogs' => $this->whenLoaded('orderStatusLogs'),
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
