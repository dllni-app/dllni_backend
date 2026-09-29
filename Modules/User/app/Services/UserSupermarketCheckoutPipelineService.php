<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Services\DeliveryPricingService;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Modules\Supermarket\Models\SmModifier;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmOrderNotificationService;
use Modules\User\Models\UserAddress;

final class UserSupermarketCheckoutPipelineService
{
    public function __construct(
        private readonly SmOrderNotificationService $notifications,
        private readonly DeliveryOrderCreationService $deliveryOrders,
        private readonly DeliveryPricingService $deliveryPricing,
        private readonly PlatformCouponRedemptionService $platformCoupons,
    ) {}

    public function preview(int $userId, int $cartId, string $fulfillmentType, string $receiveMode, ?string $scheduledAt, ?string $couponCode, ?string $note, ?int $addressId = null): array
    {
        if ($fulfillmentType === 'delivery' && $addressId === null) {
            throw ValidationException::withMessages(['addressId' => ['يرجى اختيار عنوان توصيل صالح.']]);
        }

        $cart = SmCart::query()->whereKey($cartId)->where('user_id', $userId)->with(['store', 'items.product.store'])->firstOrFail();
        if ($cart->items->isEmpty()) throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);

        $address = $this->resolveUserAddress($userId, $addressId);
        if ($fulfillmentType === 'delivery' && $address instanceof UserAddress) $this->assertDeliveryCoordinates($cart, $address);

        $storeId = $this->resolveSingleStoreId($cart);
        $subtotal = $this->refreshAndValidateCartItems($cart, false);
        $discount = $this->computeDiscount($userId, $storeId, $couponCode, $subtotal, false);
        $serviceFee = 0.0;
        $store = $cart->store ?? $cart->items->first()?->product?->store;
        $deliveryFee = $fulfillmentType === 'delivery'
            ? $this->calculateDeliveryFee($store, $address)
            : 0.0;
        $total = max(0.0, $subtotal - $discount) + $serviceFee + $deliveryFee;

        return [
            'cartId' => $cart->id,
            'merchant' => ['id' => $store?->id, 'name' => $store?->name],
            'fulfillment' => ['type' => $fulfillmentType, 'receiveMode' => $receiveMode, 'scheduledAt' => $scheduledAt, 'address' => $address ? $this->addressPayload($address) : null],
            'amounts' => ['subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'serviceFee' => round($serviceFee, 2), 'deliveryFee' => round($deliveryFee, 2), 'tax' => 0.0, 'total' => round($total, 2)],
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

        $order = DB::transaction(function () use ($userId, $cartId, $fulfillmentType, $receiveMode, $scheduledAt, $couponCode, $note, $address): SmOrder {
            $cart = SmCart::query()->whereKey($cartId)->where('user_id', $userId)->with(['store', 'items.product.store'])->lockForUpdate()->firstOrFail();
            if ($cart->items->isEmpty()) throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);

            $storeId = $this->resolveSingleStoreId($cart);
            $subtotal = $this->refreshAndValidateCartItems($cart, true);
            $platformQuote = $this->platformCoupons->quoteForPlacement(
                userId: $userId,
                section: PlatformCoupon::SECTION_SUPERMARKET,
                couponCode: $couponCode,
                subtotal: $subtotal,
            );
            $legacyCoupon = $platformQuote ? null : $this->findLegacyCoupon($storeId, $couponCode, $subtotal);
            $discount = $platformQuote
                ? $platformQuote['discount']
                : ($legacyCoupon ? $this->legacyDiscount($legacyCoupon, $subtotal) : 0.0);
            $serviceFee = 0.0;
            $store = $cart->store ?? $cart->items->first()?->product?->store;
            $deliveryFee = $fulfillmentType === 'delivery'
                ? $this->calculateDeliveryFee($store, $address)
                : 0.0;
            $total = max(0.0, $subtotal - $discount) + $serviceFee + $deliveryFee;

            $order = SmOrder::query()->create([
                'customer_id' => $userId,
                'store_id' => $storeId,
                'coupon_id' => $legacyCoupon?->id,
                'order_number' => 'SM-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => SmOrderStatus::Pending->value,
                'pickup_mode' => $receiveMode === 'scheduled' ? SmPickupMode::ScheduledPickup->value : SmPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $scheduledAt,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'service_fee' => $serviceFee,
                'delivery_fee' => $deliveryFee,
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
                    'modifier_snapshot' => $this->modifierSnapshot($item->modifier_ids ?? []),
                    'substitute_product_id' => $item->substitute_product_id,
                    'note' => $item->note,
                ]);

                if ($item->product?->stock_quantity !== null) {
                    $item->product->decrement('stock_quantity', (int) $item->quantity);
                }
            }

            if ($platformQuote) {
                $this->platformCoupons->record(
                    coupon: $platformQuote['coupon'],
                    userId: $userId,
                    section: PlatformCoupon::SECTION_SUPERMARKET,
                    subtotal: $subtotal,
                    discount: $discount,
                    order: $order,
                );
            }

            SmOrderStatusLog::query()->create(['order_id' => $order->id, 'from_status' => null, 'to_status' => SmOrderStatus::Pending->value, 'notes' => 'Order placed by customer.', 'changed_by_user_id' => $userId]);
            $cart->delete();

            return $order->fresh(['customer', 'store', 'items.product', 'statusLogs']);
        });

        if ($fulfillmentType === 'delivery' && $address instanceof UserAddress) $this->deliveryOrders->createForSupermarketOrder($order, $address);
        $order = $order->fresh(['customer', 'store', 'items.product', 'statusLogs', 'deliveryOrder.driver.user', 'deliveryOrder.driver.latestLocation', 'deliveryOrder.events']);
        $this->notifications->notifyCreated($order);

        return $order;
    }

    private function refreshAndValidateCartItems(SmCart $cart, bool $lock): float
    {
        $subtotal = 0.0;
        $requestedQuantities = $cart->items
            ->groupBy('product_id')
            ->map(fn ($items): int => (int) $items->sum('quantity'));

        foreach ($cart->items as $item) {
            $query = SmProduct::query()
                ->with(['modifierGroups.modifiers'])
                ->whereKey($item->product_id);
            if ($lock) {
                $query->lockForUpdate();
            }

            $product = $query->first();
            if (! $product || ! $product->is_available) {
                throw ValidationException::withMessages([
                    'cart' => ["المنتج {$item->product_id} لم يعد متاحاً."],
                ]);
            }

            $quantity = max(1, (int) $item->quantity);
            $totalRequested = (int) ($requestedQuantities[$item->product_id] ?? $quantity);
            if ($product->stock_quantity !== null &&
                (int) $product->stock_quantity < $totalRequested) {
                throw ValidationException::withMessages([
                    'cart' => ["الكمية المطلوبة من {$product->name} غير متوفرة حالياً."],
                ]);
            }

            $modifierIds = array_values(array_unique(array_map('intval', $item->modifier_ids ?? [])));
            sort($modifierIds);
            $modifierTotal = $this->validateAndPriceModifiers($product, $modifierIds);
            $this->validateSubstituteProduct($product, $item->substitute_product_id);

            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0) + $modifierTotal;
            if ((float) ($item->unit_price ?? 0) !== $unitPrice) {
                $item->update([
                    'unit_price' => $unitPrice,
                    'modifier_ids' => $modifierIds,
                ]);
            }
            $item->setRelation('product', $product);
            $subtotal += $unitPrice * $quantity;
        }

        return round($subtotal, 2);
    }

    private function validateAndPriceModifiers(SmProduct $product, array $modifierIds): float
    {
        $selected = collect($modifierIds);
        $price = 0.0;

        foreach ($product->modifierGroups as $group) {
            if (! $group->is_active) {
                continue;
            }

            $available = $group->modifiers->where('is_available', true);
            $selectedForGroup = $available->whereIn('id', $selected)->values();
            $count = $selectedForGroup->count();
            $min = max(0, (int) $group->min_selections);
            $max = max($min, (int) $group->max_selections);

            if ($group->is_required && $count < max(1, $min)) {
                throw ValidationException::withMessages([
                    'cart' => ["خيارات المنتج {$product->name} المطلوبة لم تعد مكتملة."],
                ]);
            }
            if ($count < $min || ($max > 0 && $count > $max)) {
                throw ValidationException::withMessages([
                    'cart' => ["خيارات المنتج {$product->name} لم تعد صالحة."],
                ]);
            }

            $price += (float) $selectedForGroup->sum('price');
        }

        $allowedIds = $product->modifierGroups
            ->where('is_active', true)
            ->flatMap(fn ($group) => $group->modifiers->where('is_available', true)->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($modifierIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'cart' => ["أحد خيارات المنتج {$product->name} لم يعد متاحاً."],
            ]);
        }

        return $price;
    }

    private function validateSubstituteProduct(SmProduct $product, ?int $substituteProductId): void
    {
        if ($substituteProductId === null) {
            return;
        }

        $substitute = SmProduct::query()->find($substituteProductId);
        if (! $substitute || ! $substitute->is_available ||
            (int) $substitute->store_id !== (int) $product->store_id) {
            throw ValidationException::withMessages([
                'cart' => ["البديل المحدد للمنتج {$product->name} لم يعد متاحاً."],
            ]);
        }
    }

    private function calculateDeliveryFee(?SmStore $store, ?UserAddress $address): float
    {
        if (! $store instanceof SmStore || ! $address instanceof UserAddress) {
            throw ValidationException::withMessages(['delivery' => ['تعذر احتساب أجرة التوصيل.']]);
        }

        $this->assertCoordinatesAvailable($store, $address);

        return (float) $this->deliveryPricing->calculate(
            pickupLatitude: (float) $store->latitude,
            pickupLongitude: (float) $store->longitude,
            dropoffLatitude: (float) $address->latitude,
            dropoffLongitude: (float) $address->longitude,
            currency: 'SYP',
        )['deliveryFee'];
    }

    private function assertCoordinatesAvailable(SmStore $store, UserAddress $address): void
    {
        if (! is_numeric($store->latitude) || ! is_numeric($store->longitude) || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            throw ValidationException::withMessages(['delivery' => ['لا يمكن احتساب التوصيل بدون تحديد موقع الاستلام والتسليم.']]);
        }
    }

    private function computeDiscount(int $userId, int $storeId, ?string $couponCode, float $subtotal, bool $lock): float
    {
        $quote = $lock
            ? $this->platformCoupons->quoteForPlacement($userId, PlatformCoupon::SECTION_SUPERMARKET, $couponCode, $subtotal)
            : $this->platformCoupons->preview($userId, PlatformCoupon::SECTION_SUPERMARKET, $couponCode, $subtotal);
        if ($quote) return $quote['discount'];
        $coupon = $this->findLegacyCoupon($storeId, $couponCode, $subtotal);
        return $coupon ? $this->legacyDiscount($coupon, $subtotal) : 0.0;
    }

    private function findLegacyCoupon(int $storeId, ?string $couponCode, float $subtotal): ?SmCoupon
    {
        if (! is_string($couponCode) || trim($couponCode) === '') return null;
        $coupon = SmCoupon::query()->where('store_id', $storeId)->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($couponCode))])->first();
        if (! $coupon || ! $coupon->is_active) return null;
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) return null;
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) return null;
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) return null;
        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) return null;
        return $coupon;
    }

    private function legacyDiscount(SmCoupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percentage') {
            $amount = $subtotal * ((float) ($coupon->percent ?? 0) / 100);
            if ($coupon->max_discount_amount !== null) $amount = min($amount, (float) $coupon->max_discount_amount);
            return round($amount, 2);
        }
        return round(min((float) ($coupon->value ?? 0), $subtotal), 2);
    }

    private function resolveSingleStoreId(SmCart $cart): int
    {
        $cartStoreId = $cart->store_id !== null ? (int) $cart->store_id : null;
        $itemStoreIds = $cart->items->map(fn ($item): ?int => $item->product?->store_id ? (int) $item->product->store_id : null)->filter()->unique()->values();
        if ($cartStoreId === null && $itemStoreIds->isEmpty()) throw ValidationException::withMessages(['cart' => ['Cart contains products that are not linked to a store.']]);
        if ($cartStoreId !== null && $itemStoreIds->contains(fn (int $storeId): bool => $storeId !== $cartStoreId)) throw ValidationException::withMessages(['cart' => ['Supermarket cart items must belong to the cart store.']]);
        if ($itemStoreIds->count() > 1) throw ValidationException::withMessages(['cart' => ['Supermarket cart items must belong to one store.']]);
        return $cartStoreId ?? (int) $itemStoreIds->first();
    }

    private function resolveUserAddress(int $userId, ?int $addressId): ?UserAddress
    {
        if ($addressId === null) return null;
        $address = UserAddress::query()->whereKey($addressId)->where('user_id', $userId)->first();
        if (! $address) throw ValidationException::withMessages(['addressId' => ['The selected address does not belong to the authenticated user.']]);
        return $address;
    }

    private function assertDeliveryCoordinates(SmCart $cart, UserAddress $address): void
    {
        $this->resolveSingleStoreId($cart);
        $store = $cart->store ?? $cart->items->first()?->product?->store;
        if ($store === null || ! is_numeric($store->latitude) || ! is_numeric($store->longitude) || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            throw ValidationException::withMessages(['delivery' => ['لا يمكن إنشاء طلب توصيل بدون تحديد موقع الاستلام والتسليم.']]);
        }
    }

    private function addressPayload(UserAddress $address): array
    {
        return ['id' => $address->id, 'label' => $address->label, 'mobile' => $address->mobile, 'city' => $address->city, 'neighborhood' => $address->neighborhood, 'street' => $address->street, 'building' => $address->building, 'floor' => $address->floor, 'directions' => $address->directions, 'latitude' => $address->latitude !== null ? (float) $address->latitude : null, 'longitude' => $address->longitude !== null ? (float) $address->longitude : null];
    }

    /**
     * Freeze modifier labels and prices at checkout so historical orders remain
     * understandable even when the store edits or deletes an option later.
     *
     * @param  array<int, mixed>  $modifierIds
     * @return array<int, array<string, mixed>>
     */
    private function modifierSnapshot(array $modifierIds): array
    {
        $ids = collect($modifierIds)
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return SmModifier::query()
            ->whereIn('id', $ids)
            ->with('modifierGroup:id,name')
            ->get()
            ->sortBy(fn (SmModifier $modifier): int => (int) $ids->search((int) $modifier->id))
            ->map(static fn (SmModifier $modifier): array => [
                'id' => (int) $modifier->id,
                'modifierGroupId' => (int) $modifier->modifier_group_id,
                'groupName' => $modifier->modifierGroup?->name,
                'name' => $modifier->name,
                'price' => (int) $modifier->price,
            ])
            ->values()
            ->all();
    }

}
