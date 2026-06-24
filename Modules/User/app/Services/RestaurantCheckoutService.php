<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\PromoCode;

final class RestaurantCheckoutService
{
    public function __construct(
        private readonly RestaurantCartNormalizerService $cartNormalizer,
    ) {}

    /**
     * Creates a single Order containing all cart items.
     * When items span multiple restaurants restaurant_id is set to null
     * (merchant context is preserved at the item level via product_id).
     * The cart is fully deleted after the order is placed.
     */
    public function checkoutAll(
        int $userId,
        string $orderType,
        ?string $pickupMode = null,
        ?string $pickupScheduledFor = null,
        ?string $promoCode = null,
        ?string $specialInstructions = null,
    ): Order {
        return DB::transaction(function () use ($userId, $orderType, $pickupMode, $pickupScheduledFor, $promoCode, $specialInstructions): Order {
            $cart = Cart::query()
                ->where('user_id', $userId)
                ->first();

            if (! $cart) {
                throw ValidationException::withMessages([
                    'cart' => ['Cart is empty.'],
                ]);
            }

            $cart = $this->cartNormalizer->normalize($cart);

            if ($cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Cart is empty.'],
                ]);
            }

            $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->total_price ?? 0));

            $restaurantIds = $cart->items
                ->pluck('product.restaurant_id')
                ->filter()
                ->unique()
                ->values();

            $isSingleMerchant = $restaurantIds->count() === 1;
            $restaurantId = $isSingleMerchant ? (int) $restaurantIds->first() : null;

            [$discountAmount, $promoCodeId] = $this->resolveDiscount(
                $restaurantId,
                $promoCode,
                $subtotal,
                $isSingleMerchant,
            );

            $taxAmount = 0.0;
            $serviceFee = 0.0;
            $totalAmount = max(0.0, $subtotal - $discountAmount) + $taxAmount + $serviceFee;

            $order = Order::create([
                'user_id' => $userId,
                'restaurant_id' => $restaurantId,
                'promo_code_id' => $promoCodeId,
                'order_number' => 'ORD-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
                'status' => OrderStatus::Pending->value,
                'order_type' => $orderType,
                'pickup_mode' => $pickupMode ?? RestaurantPickupMode::ImmediatePickup->value,
                'pickup_scheduled_for' => $pickupScheduledFor,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,
                'special_instructions' => $specialInstructions,
            ]);

            foreach ($cart->items as $cartItem) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem->product_id,
                    'substitute_product_id' => $cartItem->substitute_product_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'total_price' => $cartItem->total_price,
                    'special_instructions' => $cartItem->special_instructions,
                ]);

                $modifierRows = DB::table('cart_item_modifier')
                    ->where('cart_item_id', $cartItem->id)
                    ->get(['modifier_id', 'price'])
                    ->map(fn ($row): array => [
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => (int) $row->modifier_id,
                        'price' => (float) $row->price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($modifierRows !== []) {
                    DB::table('order_item_modifier')->insert($modifierRows);
                }
            }

            $cart->delete();

            return $order;
        });
    }

    /**
     * @return array{float, int|null}
     */
    private function resolveDiscount(?int $restaurantId, ?string $promoCode, float $subtotal, bool $isSingleMerchant): array
    {
        if (! $isSingleMerchant || ! is_string($promoCode) || mb_trim($promoCode) === '' || $restaurantId === null) {
            return [0.0, null];
        }

        $promo = PromoCode::query()
            ->where('code', $promoCode)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (! $promo || ! $this->promoIsValid($promo, $subtotal)) {
            return [0.0, null];
        }

        return [$this->calculateDiscount($promo, $subtotal), $promo->id];
    }

    private function promoIsValid(PromoCode $promo, float $subtotal): bool
    {
        if (! $promo->is_active) {
            return false;
        }

        if ($promo->starts_at && now()->lessThan($promo->starts_at)) {
            return false;
        }

        if ($promo->ends_at && now()->greaterThan($promo->ends_at)) {
            return false;
        }

        if ($promo->min_order_amount !== null && $subtotal < (float) $promo->min_order_amount) {
            return false;
        }

        if ($promo->usage_limit !== null && (int) $promo->usage_count >= (int) $promo->usage_limit) {
            return false;
        }

        return true;
    }

    private function calculateDiscount(PromoCode $promo, float $subtotal): float
    {
        return match ($promo->discount_type) {
            DiscountType::Percentage => round($subtotal * ((float) $promo->discount_value / 100), 2),
            DiscountType::FixedAmount => round(min((float) $promo->discount_value, $subtotal), 2),
            default => 0.0,
        };
    }
}
