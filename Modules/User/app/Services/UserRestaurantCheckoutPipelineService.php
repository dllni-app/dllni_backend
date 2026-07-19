<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Services\RestaurantOrderNotificationService;
use Modules\User\Models\UserAddress;

final class UserRestaurantCheckoutPipelineService
{
    public function __construct(
        private readonly RestaurantCheckoutService $checkoutService,
        private readonly RestaurantOrderNotificationService $notifications,
        private readonly DeliveryOrderCreationService $deliveryOrders,
        private readonly PlatformCouponRedemptionService $platformCoupons,
    ) {}

    public function preview(int $userId, int $cartId, string $fulfillmentType, string $receiveMode, ?string $scheduledAt, ?string $couponCode, ?string $note, ?int $addressId = null): array
    {
        $cart = Cart::query()->whereKey($cartId)->where('user_id', $userId)->whereNotNull('restaurant_id')->with(['restaurant', 'items.product'])->firstOrFail();
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        $this->assertCartItemsBelongToRestaurant($cart);
        $address = $this->resolveUserAddress($userId, $addressId);
        if ($fulfillmentType === OrderType::Delivery->value) {
            if (! $address instanceof UserAddress) {
                throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
            }
            $this->assertDeliveryCoordinates($cart, $address);
        }

        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->total_price ?? 0));
        $discount = $this->computeDiscount($userId, (int) $cart->restaurant_id, $couponCode, $subtotal);
        $serviceFee = 0.0;
        $tax = 0.0;
        $total = max(0.0, $subtotal - $discount) + $serviceFee + $tax;

        return [
            'cartId' => $cart->id,
            'merchant' => ['id' => $cart->restaurant?->id, 'name' => $cart->restaurant?->name],
            'fulfillment' => ['type' => $fulfillmentType, 'receiveMode' => $receiveMode, 'scheduledAt' => $scheduledAt, 'address' => $address ? $this->addressPayload($address) : null],
            'amounts' => ['subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'serviceFee' => round($serviceFee, 2), 'tax' => round($tax, 2), 'total' => round($total, 2)],
            'note' => $note,
        ];
    }

    public function place(int $userId, int $cartId, string $fulfillmentType, string $receiveMode, ?string $scheduledAt, ?string $couponCode, ?string $note, ?int $addressId = null): Order
    {
        if ($fulfillmentType === OrderType::Delivery->value && $addressId === null) {
            throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
        }

        $address = $this->resolveUserAddress($userId, $addressId);
        if ($fulfillmentType === OrderType::Delivery->value) {
            $cart = Cart::query()->whereKey($cartId)->where('user_id', $userId)->whereNotNull('restaurant_id')->with(['restaurant', 'items.product'])->firstOrFail();
            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
            }
            $this->assertCartItemsBelongToRestaurant($cart);
            if (! $address instanceof UserAddress) {
                throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
            }
            $this->assertDeliveryCoordinates($cart, $address);
        }

        $order = $this->checkoutService->checkoutCart(
            userId: $userId,
            cartId: $cartId,
            orderType: $fulfillmentType,
            pickupMode: $receiveMode === 'scheduled' ? RestaurantPickupMode::ScheduledPickup->value : RestaurantPickupMode::ImmediatePickup->value,
            pickupScheduledFor: $scheduledAt,
            promoCode: $couponCode,
            specialInstructions: $note,
            userAddressId: $address?->id,
        );

        OrderStatusLog::query()->firstOrCreate(
            ['order_id' => $order->id, 'to_status' => OrderStatus::Pending->value],
            ['from_status' => null, 'note' => 'Order placed by customer.']
        );

        $order = $order->fresh(['restaurant', 'user', 'userAddress', 'orderItems.product', 'orderStatusLogs']);
        if ($fulfillmentType === OrderType::Delivery->value) {
            $this->deliveryOrders->createForRestaurantOrder($order);
        }

        $order = $order->fresh(['restaurant', 'userAddress', 'orderItems.product', 'orderStatusLogs', 'deliveryOrder.driver.user', 'deliveryOrder.driver.latestLocation', 'deliveryOrder.events']);
        $this->notifications->notifyCreated($order);

        return $order;
    }

    private function computeDiscount(int $userId, int $restaurantId, ?string $couponCode, float $subtotal): float
    {
        $platformQuote = $this->platformCoupons->preview(
            userId: $userId,
            section: PlatformCoupon::SECTION_RESTAURANT,
            couponCode: $couponCode,
            subtotal: $subtotal,
        );
        if ($platformQuote) {
            return $platformQuote['discount'];
        }

        if (! is_string($couponCode) || trim($couponCode) === '') return 0.0;
        $coupon = PromoCode::query()->where('restaurant_id', $restaurantId)->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($couponCode))])->first();
        if (! $coupon || ! $coupon->is_active) return 0.0;
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) return 0.0;
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) return 0.0;
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) return 0.0;
        if ($coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit) return 0.0;
        if ($coupon->discount_type?->value === 'percentage') return round($subtotal * ((float) $coupon->discount_value / 100), 2);

        return round(min((float) $coupon->discount_value, $subtotal), 2);
    }

    private function resolveUserAddress(int $userId, ?int $addressId): ?UserAddress
    {
        if ($addressId === null) return null;
        $address = UserAddress::query()->whereKey($addressId)->where('user_id', $userId)->first();
        if (! $address) {
            throw ValidationException::withMessages(['addressId' => ['The selected address does not belong to the authenticated user.']]);
        }
        return $address;
    }

    private function addressPayload(UserAddress $address): array
    {
        return ['id' => $address->id, 'label' => $address->label, 'mobile' => $address->mobile, 'city' => $address->city, 'neighborhood' => $address->neighborhood, 'street' => $address->street, 'building' => $address->building, 'floor' => $address->floor, 'directions' => $address->directions, 'latitude' => $address->latitude !== null ? (float) $address->latitude : null, 'longitude' => $address->longitude !== null ? (float) $address->longitude : null];
    }

    private function assertCartItemsBelongToRestaurant(Cart $cart): void
    {
        if ($cart->items->contains(fn ($item): bool => (int) $item->product?->restaurant_id !== (int) $cart->restaurant_id)) {
            throw ValidationException::withMessages(['cart' => ['Cart contains items from another restaurant.']]);
        }
    }

    private function assertDeliveryCoordinates(Cart $cart, UserAddress $address): void
    {
        if ($cart->restaurant === null || ! is_numeric($cart->restaurant->latitude) || ! is_numeric($cart->restaurant->longitude) || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            throw ValidationException::withMessages(['delivery' => ['لا يمكن إنشاء طلب توصيل بدون تحديد موقع الاستلام والتسليم.']]);
        }
    }
}
