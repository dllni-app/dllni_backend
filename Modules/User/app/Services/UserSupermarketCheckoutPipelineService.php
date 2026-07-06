<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Services\SmOrderNotificationService;
use Modules\User\Models\UserAddress;

final class UserSupermarketCheckoutPipelineService
{
    public function __construct(
        private readonly SmOrderNotificationService $notifications,
        private readonly DeliveryOrderCreationService $deliveryOrders,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $userId, int $cartId, string $fulfillmentType, string $receiveMode, ?string $scheduledAt, ?string $couponCode, ?string $note, ?int $addressId = null): array
    {
        if ($fulfillmentType === 'delivery' && $addressId === null) {
            throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
        }

        $cart = SmCart::query()->whereKey($cartId)->where('user_id', $userId)->with(['store', 'items.product.store'])->firstOrFail();
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        $address = $this->resolveUserAddress($userId, $addressId);
        if ($fulfillmentType === 'delivery' && $address instanceof UserAddress) {
            $this->assertDeliveryCoordinates($cart, $address);
        }

        $storeId = $this->resolveSingleStoreId($cart);
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);
        $discount = $this->computeDiscount($storeId, $couponCode, $subtotal);
        $serviceFee = 0.0;
        $total = max(0.0, $subtotal - $discount) + $serviceFee;
        $store = $cart->store ?? $cart->items->first()?->product?->store;

        return [
            'cartId' => $cart->id,
            'merchant' => ['id' => $store?->id, 'name' => $store?->name],
            'fulfillment' => [
                'type' => $fulfillmentType,
                'receiveMode' => $receiveMode,
                'scheduledAt' => $scheduledAt,
                'address' => $address ? $this->addressPayload($address) : null,
            ],
            'amounts' => ['subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'serviceFee' => round($serviceFee, 2), 'tax' => 0.0, 'total' => round($total, 2)],
            'note' => $note,
        ];
    }

    public function place(int $userId, int $cartId, string $fulfillmentType, string $receiveMode, ?string $scheduledAt, ?string $couponCode, ?string $note, ?int $addressId = null): SmOrder
    {
        if ($fulfillmentType === 'delivery' && $addressId === null) {
            throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
        }

        $address = $this->resolveUserAddress($userId, $addressId);
        if ($fulfillmentType === 'delivery' && $address instanceof UserAddress) {
            $cartForValidation = SmCart::query()->whereKey($cartId)->where('user_id', $userId)->with(['store', 'items.product.store'])->firstOrFail();
            $this->assertDeliveryCoordinates($cartForValidation, $address);
        }

        $order = DB::transaction(function () use ($userId, $cartId, $receiveMode, $scheduledAt, $couponCode, $note): SmOrder {
            $cart = SmCart::query()->whereKey($cartId)->where('user_id', $userId)->with(['store', 'items.product.store'])->lockForUpdate()->firstOrFail();
            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
            }

            $storeId = $this->resolveSingleStoreId($cart);
            $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);
            $coupon = $this->findCoupon($storeId, $couponCode, $subtotal);
            $discount = $coupon ? $this->computeDiscount($storeId, $couponCode, $subtotal) : 0.0;
            $serviceFee = 0.0;
            $total = max(0.0, $subtotal - $discount) + $serviceFee;

            $order = SmOrder::query()->create([
                'customer_id' => $userId,
                'store_id' => $storeId,
                'coupon_id' => $coupon?->id,
                'order_number' => 'SM-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => SmOrderStatus::Pending->value,
                'pickup_mode' => $receiveMode === 'scheduled' ? SmPickupMode::ScheduledPickup->value : SmPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $scheduledAt,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'service_fee' => $serviceFee,
                'total_amount' => $total,
                'special_instructions' => $note,
            ]);

            foreach ($cart->items as $item) {
                SmOrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => (float) ($item->unit_price ?? 0) * (int) $item->quantity,
                    'product_name' => $item->product?->name,
                ]);
            }

            SmOrderStatusLog::query()->create(['order_id' => $order->id, 'from_status' => null, 'to_status' => SmOrderStatus::Pending->value, 'notes' => 'Order placed by customer.', 'changed_by_user_id' => $userId]);
            $cart->delete();

            return $order->fresh(['customer', 'store', 'items.product', 'statusLogs']);
        });

        if ($fulfillmentType === 'delivery' && $address instanceof UserAddress) {
            $this->deliveryOrders->createForSupermarketOrder($order, $address);
        }

        $order = $order->fresh(['customer', 'store', 'items.product', 'statusLogs', 'deliveryOrder.driver.user', 'deliveryOrder.driver.latestLocation', 'deliveryOrder.events']);
        $this->notifications->notifyCreated($order);

        return $order;
    }

    private function computeDiscount(int $storeId, ?string $couponCode, float $subtotal): float
    {
        $coupon = $this->findCoupon($storeId, $couponCode, $subtotal);
        if (! $coupon) {
            return 0.0;
        }
        if ($coupon->type === 'percentage') {
            $amount = $subtotal * ((float) ($coupon->percent ?? 0) / 100);
            if ($coupon->max_discount_amount !== null) {
                $amount = min($amount, (float) $coupon->max_discount_amount);
            }
            return round($amount, 2);
        }
        return round(min((float) ($coupon->value ?? 0), $subtotal), 2);
    }

    private function findCoupon(int $storeId, ?string $couponCode, float $subtotal): ?SmCoupon
    {
        if (! is_string($couponCode) || mb_trim($couponCode) === '') {
            return null;
        }
        $coupon = SmCoupon::query()->where('store_id', $storeId)->where('code', $couponCode)->first();
        if (! $coupon || ! $coupon->is_active) {
            return null;
        }
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return null;
        }
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return null;
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return null;
        }
        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
            return null;
        }
        return $coupon;
    }

    private function resolveSingleStoreId(SmCart $cart): int
    {
        $cartStoreId = $cart->store_id !== null ? (int) $cart->store_id : null;
        $itemStoreIds = $cart->items->map(fn ($item): ?int => $item->product?->store_id ? (int) $item->product->store_id : null)->filter()->unique()->values();
        if ($cartStoreId === null && $itemStoreIds->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart contains products that are not linked to a store.']]);
        }
        if ($cartStoreId !== null && $itemStoreIds->contains(fn (int $storeId): bool => $storeId !== $cartStoreId)) {
            throw ValidationException::withMessages(['cart' => ['Supermarket cart items must belong to the cart store.']]);
        }
        if ($itemStoreIds->count() > 1) {
            throw ValidationException::withMessages(['cart' => ['Supermarket cart items must belong to one store.']]);
        }
        return $cartStoreId ?? (int) $itemStoreIds->first();
    }

    private function resolveUserAddress(int $userId, ?int $addressId): ?UserAddress
    {
        if ($addressId === null) {
            return null;
        }
        $address = UserAddress::query()->whereKey($addressId)->where('user_id', $userId)->first();
        if (! $address) {
            throw ValidationException::withMessages(['addressId' => ['The selected address does not belong to the authenticated user.']]);
        }
        return $address;
    }

    private function assertDeliveryCoordinates(SmCart $cart, UserAddress $address): void
    {
        $this->resolveSingleStoreId($cart);
        $store = $cart->store ?? $cart->items->first()?->product?->store;
        if ($store === null || ! is_numeric($store->latitude) || ! is_numeric($store->longitude) || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            throw ValidationException::withMessages([
                'delivery' => ['لا يمكن إنشاء طلب توصيل بدون تحديد موقع الاستلام والتسليم.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function addressPayload(UserAddress $address): array
    {
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
        ];
    }
}
