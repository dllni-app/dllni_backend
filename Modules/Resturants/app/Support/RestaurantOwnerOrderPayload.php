<?php

declare(strict_types=1);

namespace Modules\Resturants\Support;

use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\User\Models\UserAddress;

final class RestaurantOwnerOrderPayload
{
    public function build(Order $order): array
    {
        $status = $order->status?->value ?? $order->status;
        $canEditItems = in_array($status, [OrderStatus::Pending->value, OrderStatus::Accepted->value], true);
        $address = $this->resolveAddress($order);
        $deliveryFee = 0.0;
        $subtotal = (float) ($order->subtotal ?? 0);
        $discount = (float) ($order->discount_amount ?? 0);
        $tax = (float) ($order->tax_amount ?? 0);
        $serviceFee = (float) ($order->service_fee ?? 0);
        $total = (float) ($order->total_amount ?? 0);

        return [
            ...OrderResource::make($order)->resolve(),
            'customer' => $order->relationLoaded('user') && $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'phone' => $order->user->phone,
                'mobile' => $order->user->phone,
                'email' => $order->user->email,
            ] : null,
            'customerAddress' => $address ? $this->addressPayload($address) : null,
            'delivery' => [
                'orderType' => $order->order_type?->value ?? $order->order_type,
                'orderTypeLabelAr' => $this->orderTypeLabel($order->order_type?->value ?? $order->order_type),
                'pickupMode' => $order->pickup_mode?->value ?? $order->pickup_mode,
                'scheduledFor' => $order->pickup_scheduled_for?->toDateTimeString(),
                'address' => $address ? $this->addressPayload($address) : null,
                'deliveryFee' => $deliveryFee,
                'distanceKm' => null,
                'estimatedDeliveryMinutes' => null,
            ],
            'items' => $order->relationLoaded('orderItems') ? $order->orderItems->map(fn ($item) => [
                'id' => $item->id,
                'orderId' => $item->order_id,
                'productId' => $item->product_id,
                'name' => $item->product?->name,
                'imageUrl' => $item->product?->getFirstMediaUrl('primary-image') ?: null,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) ($item->unit_price ?? 0),
                'totalPrice' => (float) ($item->total_price ?? 0),
                'specialInstructions' => $item->special_instructions,
            ])->values()->all() : [],
            'amounts' => [
                'subtotal' => $subtotal,
                'deliveryFee' => $deliveryFee,
                'tax' => $tax,
                'serviceFee' => $serviceFee,
                'discount' => $discount,
                'total' => $total,
            ],
            'canEditItems' => $canEditItems,
            'paymentBreakdown' => [
                'subtotal' => $subtotal,
                'deliveryFee' => $deliveryFee,
                'serviceFee' => $serviceFee,
                'tax' => $tax,
                'discount' => $discount,
                'total' => $total,
            ],
        ];
    }

    public function canEdit(Order $order): bool
    {
        $status = $order->status?->value ?? $order->status;

        return in_array($status, [OrderStatus::Pending->value, OrderStatus::Accepted->value], true);
    }

    private function resolveAddress(Order $order): ?UserAddress
    {
        if ($order->relationLoaded('userAddress') && $order->userAddress) {
            return $order->userAddress;
        }

        if ($order->relationLoaded('user') && $order->user?->relationLoaded('addresses')) {
            return $order->user->addresses->firstWhere('is_default', true)
                ?? $order->user->addresses->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(UserAddress $address): array
    {
        $parts = array_values(array_filter([
            $address->city,
            $address->neighborhood,
            $address->street,
            $address->building ? 'بناء '.$address->building : null,
            $address->floor ? 'طابق '.$address->floor : null,
            $address->directions,
        ], fn ($part): bool => is_string($part) && trim($part) !== ''));

        return [
            'id' => $address->id,
            'label' => $address->label,
            'mobile' => $address->mobile,
            'city' => $address->city,
            'neighborhood' => $address->neighborhood,
            'street' => $address->street,
            'building' => $address->building,
            'floor' => $address->floor,
            'directions' => $address->directions,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'isDefault' => (bool) $address->is_default,
            'formatted' => implode('، ', $parts),
        ];
    }

    private function orderTypeLabel(?string $orderType): ?string
    {
        return match ($orderType) {
            'delivery' => 'توصيل',
            'pickup' => 'استلام',
            'dine_in' => 'داخل المطعم',
            default => $orderType,
        };
    }
}
