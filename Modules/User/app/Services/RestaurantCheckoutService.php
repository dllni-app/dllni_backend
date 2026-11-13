<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Services\Promotions\PromotionPricingResult;
use Modules\Resturants\Services\Promotions\RestaurantCartPricingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Modules\Delivery\Services\DeliveryPricingService;
use Modules\User\Models\UserAddress;

final class RestaurantCheckoutService
{
    public function __construct(
        private readonly PlatformCouponRedemptionService $platformCoupons,
        private readonly DeliveryPricingService $deliveryPricing,
        private readonly RestaurantCartPricingService $pricing,
    ) {}

    public function checkoutCart(
        int $userId,
        int $cartId,
        string $orderType,
        ?string $pickupMode = null,
        ?string $pickupScheduledFor = null,
        ?string $promoCode = null,
        ?string $specialInstructions = null,
        ?int $userAddressId = null,
        ?PromotionPricingResult $confirmedPricing = null,
        bool $promotionConfirmed = false,
    ): Order {
        return DB::transaction(function () use ($userId, $cartId, $orderType, $pickupMode, $pickupScheduledFor, $promoCode, $specialInstructions, $userAddressId, $confirmedPricing, $promotionConfirmed): Order {
            $cart = Cart::query()
                ->whereKey($cartId)
                ->where('user_id', $userId)
                ->whereNotNull('restaurant_id')
                ->with(['restaurant', 'items.product', 'items.modifiers.modifierGroup'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
            }

            $this->assertCartItemsBelongToRestaurant($cart);
            $pricing = $this->pricing->price($cart, true);
            if ($confirmedPricing !== null && ! hash_equals($confirmedPricing->fingerprint, $pricing->fingerprint)) {
                throw new ConflictHttpException('QUOTE_CHANGED');
            }
            $this->pricing->assertPlaceable($cart, $pricing, $promotionConfirmed);
            $subtotal = PromotionPricingResult::money($pricing->netSubtotal);
            $restaurantId = (int) $cart->restaurant_id;

            $platformQuote = $this->platformCoupons->quoteForPlacement(
                userId: $userId,
                section: PlatformCoupon::SECTION_RESTAURANT,
                couponCode: $promoCode,
                subtotal: $subtotal,
            );

            if ($platformQuote) {
                $discountAmount = $platformQuote['discount'];
                $promoCodeId = null;
            } else {
                [$discountAmount, $promoCodeId] = $this->resolveLegacyDiscount($restaurantId, $promoCode, $subtotal);
            }

            $taxAmount = 0.0;
            $serviceFee = 0.0;
            $deliveryFee = 0.0;
            if ($orderType === OrderType::Delivery->value) {
                $address = UserAddress::query()->whereKey($userAddressId)->where('user_id', $userId)->first();
                if (! $address instanceof UserAddress || $cart->restaurant === null ||
                    ! is_numeric($cart->restaurant->latitude) || ! is_numeric($cart->restaurant->longitude) ||
                    ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
                    throw ValidationException::withMessages(['delivery' => ['تعذر احتساب أجرة التوصيل.']]);
                }
                $deliveryFee = (float) $this->deliveryPricing->calculate(
                    pickupLatitude: (float) $cart->restaurant->latitude,
                    pickupLongitude: (float) $cart->restaurant->longitude,
                    dropoffLatitude: (float) $address->latitude,
                    dropoffLongitude: (float) $address->longitude,
                    currency: 'SYP',
                )['deliveryFee'];
            }
            $totalAmount = max(0.0, $subtotal - $discountAmount) + $taxAmount + $serviceFee + $deliveryFee;

            $order = Order::create([
                'user_id' => $userId,
                'user_address_id' => $userAddressId,
                'restaurant_id' => $restaurantId,
                'promo_code_id' => $promoCodeId,
                'order_number' => 'ORD-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => OrderStatus::Pending->value,
                'order_type' => $orderType,
                'pickup_mode' => $pickupMode ?? RestaurantPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $pickupScheduledFor,
                'subtotal' => $subtotal,
                'original_items_subtotal' => PromotionPricingResult::money($pricing->originalSubtotal),
                'promotion_discount_amount' => PromotionPricingResult::money($pricing->promotionDiscount),
                'promotion_snapshot' => ['fingerprint' => $pricing->fingerprint, 'appliedPromotions' => $pricing->promotionsPayload(), 'ordinaryDiscount' => PromotionPricingResult::money($pricing->ordinaryDiscount), 'boundedFallback' => $pricing->boundedFallback],
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,
                'special_instructions' => $specialInstructions,
            ]);

            $hasProductSnapshot = Schema::hasColumn('order_items', 'product_name');
            $hasModifierSnapshot = Schema::hasColumn('order_items', 'modifier_snapshot');
            foreach ($cart->items as $cartItem) {
                foreach ($pricing->lines[(int) $cartItem->id]['allocations'] as $segment) {
                    $quantity = $segment['quantity'];
                    $orderItemAttributes = [
                        'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'substitute_product_id' => $cartItem->substitute_product_id,
                        'quantity' => $quantity,
                        'unit_price' => PromotionPricingResult::money($segment['netUnit']),
                        'total_price' => PromotionPricingResult::money($segment['netUnit'] * $quantity),
                        'original_unit_price' => PromotionPricingResult::money($segment['originalUnit']),
                        'original_total_price' => PromotionPricingResult::money($segment['originalUnit'] * $quantity),
                        'promotion_discount_amount' => PromotionPricingResult::money($segment['promotionDiscount'] * $quantity),
                        'reward_quantity' => $segment['role'] === 'reward' ? $quantity : 0,
                        'promotion_snapshot' => $segment['offerId'] === null ? null : [
                            'offerId' => $segment['offerId'],
                            'role' => $segment['role'],
                            'freeQuantity' => $segment['free'] ? $quantity : 0,
                            'sourceCartItemId' => $cartItem->id,
                        ],
                        'special_instructions' => $cartItem->special_instructions,
                    ];
                    if ($hasProductSnapshot) {
                        $orderItemAttributes['product_name'] = $cartItem->product?->name;
                    }
                    if ($hasModifierSnapshot) {
                        $orderItemAttributes['modifier_snapshot'] = $cartItem->modifiers->map(fn ($modifier): array => [
                            'id' => (int) $modifier->id,
                            'modifierGroupId' => (int) $modifier->modifier_group_id,
                            'groupName' => $modifier->modifierGroup?->name,
                            'name' => $modifier->name,
                            'price' => (float) ($modifier->price ?? 0),
                        ])->values()->all();
                    }
                    $orderItem = OrderItem::create($orderItemAttributes);
                    $modifierRows = $cartItem->modifiers->map(fn ($modifier): array => [
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => (int) $modifier->id,
                        'price' => (float) ($modifier->price ?? 0),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all();
                    if ($modifierRows !== []) {
                        DB::table('order_item_modifier')->insert($modifierRows);
                    }
                }
            }

            foreach ($pricing->appliedPromotions as $application) {
                $rule = $application['rule'];
                $order->promotionApplications()->create([
                    'offer_id' => $rule['offerId'],
                    'original_offer_id' => $rule['offerId'],
                    'restaurant_id' => $restaurantId,
                    'mechanism' => $rule['offerType'],
                    'rule_version' => $rule['ruleVersion'],
                    'application_count' => $application['applications'],
                    'reward_quantity' => $application['rewardQuantity'],
                    'free_quantity' => $application['freeQuantity'],
                    'discount_amount' => PromotionPricingResult::money($application['discount']),
                    'net_eligible_revenue' => PromotionPricingResult::money($application['netRevenue']),
                    'currency' => 'SYP',
                    'rule_snapshot' => $rule,
                    'status' => 'applied',
                    'applied_at' => now(),
                ]);
            }

            if ($platformQuote) {
                $this->platformCoupons->record(
                    coupon: $platformQuote['coupon'],
                    userId: $userId,
                    section: PlatformCoupon::SECTION_RESTAURANT,
                    subtotal: $subtotal,
                    discount: $discountAmount,
                    order: $order,
                );
            }

            $cart->delete();

            return $order->fresh();
        });
    }

    /** @return array{float, int|null} */
    private function resolveLegacyDiscount(int $restaurantId, ?string $promoCode, float $subtotal): array
    {
        if (! is_string($promoCode) || trim($promoCode) === '') {
            return [0.0, null];
        }

        $promo = PromoCode::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($promoCode))])
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (! $promo || ! $this->promoIsValid($promo, $subtotal)) {
            return [0.0, null];
        }

        return [$this->calculateLegacyDiscount($promo, $subtotal), $promo->id];
    }

    private function promoIsValid(PromoCode $promo, float $subtotal): bool
    {
        if (! $promo->is_active) return false;
        if ($promo->starts_at && now()->lessThan($promo->starts_at)) return false;
        if ($promo->ends_at && now()->greaterThan($promo->ends_at)) return false;
        if ($promo->min_order_amount !== null && $subtotal < (float) $promo->min_order_amount) return false;
        if ($promo->usage_limit !== null && (int) $promo->usage_count >= (int) $promo->usage_limit) return false;

        return true;
    }

    private function calculateLegacyDiscount(PromoCode $promo, float $subtotal): float
    {
        return match ($promo->discount_type) {
            DiscountType::Percentage => round($subtotal * ((float) $promo->discount_value / 100), 2),
            DiscountType::FixedAmount => round(min((float) $promo->discount_value, $subtotal), 2),
            default => 0.0,
        };
    }

    private function assertCartItemsBelongToRestaurant(Cart $cart): void
    {
        if ($cart->items->contains(fn ($item): bool => (int) $item->product?->restaurant_id !== (int) $cart->restaurant_id)) {
            throw ValidationException::withMessages(['cart' => ['Cart contains items from another restaurant.']]);
        }
    }
}