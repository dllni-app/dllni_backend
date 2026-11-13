<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Services\Promotions\PromotionPricingResult;
use Modules\Resturants\Services\Promotions\RestaurantCartPricingService;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmOrder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CommerceCheckoutQuoteService
{
    public function issue(int $userId, string $section, int $cartId, array $selection, array $amounts, ?string $expectedPricingFingerprint = null): array
    {
        return DB::transaction(fn (): array => $this->issueLocked($userId, $section, $cartId, $selection, $amounts, $expectedPricingFingerprint));
    }

    private function issueLocked(int $userId, string $section, int $cartId, array $selection, array $amounts, ?string $expectedPricingFingerprint): array
    {
        $id = (string) Str::uuid();
        $expiresAt = now()->addMinutes(5);
        [$fingerprint, $pricing, $cart] = $this->fingerprintContext($userId, $section, $cartId);
        if ($pricing !== null) {
            if ($expectedPricingFingerprint !== null && ! hash_equals($expectedPricingFingerprint, $pricing->fingerprint)) {
                throw new ConflictHttpException('QUOTE_CHANGED');
            }
            $service = app(RestaurantCartPricingService::class);
            $service->assertPreviewable($cart, $pricing);
            $service->rememberAdvancedPricing($cart, $pricing);
            $amounts['pricingFingerprint'] = $pricing->fingerprint;
            $offers = Offer::query()->where('restaurant_id', $cart->restaurant_id)
                ->where('is_active', true)
                ->whereHas('products', fn ($query) => $query->whereIn('products.id', $cart->items->pluck('product_id')))
                ->get(['starts_at', 'ends_at']);
            foreach ($offers as $offer) {
                foreach ([$offer->starts_at, $offer->ends_at?->copy()->addSecond()] as $boundary) {
                    if ($boundary !== null && $boundary->isFuture() && $boundary->lessThan($expiresAt)) {
                        $expiresAt = $boundary;
                    }
                }
            }
        }
        DB::table('commerce_checkout_quotes')->insert([
            'id' => $id,
            'user_id' => $userId,
            'section' => $section,
            'cart_id' => $cartId,
            'cart_fingerprint' => $fingerprint,
            'selection' => json_encode($selection, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'amounts' => json_encode($amounts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['quoteId' => $id, 'expiresAt' => $expiresAt->toIso8601String()];
    }

    public function assertCurrent(int $userId, string $section, int $cartId, string $quoteId, array $selection): ?PromotionPricingResult
    {
        $quote = DB::table('commerce_checkout_quotes')
            ->where('id', $quoteId)
            ->where('user_id', $userId)
            ->where('section', $section)
            ->where('cart_id', $cartId)
            ->lockForUpdate()
            ->first();

        if ($quote === null || $quote->consumed_order_id !== null) {
            throw new ConflictHttpException('QUOTE_NOT_AVAILABLE');
        }

        if (now()->greaterThanOrEqualTo(Carbon::parse($quote->expires_at))) {
            throw new ConflictHttpException('QUOTE_EXPIRED');
        }

        [$fingerprint, $pricing] = $this->fingerprintContext($userId, $section, $cartId);
        $storedSelection = json_decode((string) $quote->selection, true, 512, JSON_THROW_ON_ERROR);
        if ($this->canonicalSelection($storedSelection) !== $this->canonicalSelection($selection) || ! hash_equals(
            (string) $quote->cart_fingerprint,
            $fingerprint,
        )) {
            throw new ConflictHttpException('QUOTE_CHANGED');
        }

        return $pricing;
    }

    public function consume(int $userId, string $section, string $quoteId, Order|SmOrder $order): void
    {
        $quote = DB::table('commerce_checkout_quotes')
            ->where('id', $quoteId)
            ->where('user_id', $userId)
            ->where('section', $section)
            ->lockForUpdate()
            ->first();

        if ($quote === null || $quote->consumed_order_id !== null) {
            throw new ConflictHttpException('QUOTE_NOT_AVAILABLE');
        }

        $expected = json_decode((string) $quote->amounts, true, 512, JSON_THROW_ON_ERROR);
        $subtotal = (float) $order->subtotal;
        $discount = (float) $order->discount_amount;
        $fee = (float) $order->service_fee;
        $tax = $section === 'restaurant' ? (float) $order->tax_amount : 0.0;
        $deliveryFee = $section === 'restaurant'
            ? (float) $order->total_amount - max(0.0, $subtotal - $discount) - $tax - $fee
            : (float) $order->delivery_fee;

        $actual = [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'serviceFee' => $fee,
            'deliveryFee' => $deliveryFee,
            'tax' => $tax,
            'total' => (float) $order->total_amount,
        ];

        if ($section === 'restaurant' && isset($expected['pricingFingerprint'])) {
            if (! hash_equals((string) $expected['pricingFingerprint'], (string) ($order->promotion_snapshot['fingerprint'] ?? ''))) {
                throw new ConflictHttpException('QUOTE_CHANGED');
            }
            if (isset($expected['promotionDiscount'])) {
                $actual['promotionDiscount'] = (float) $order->promotion_discount_amount;
                $actual['originalSubtotal'] = (float) $order->original_items_subtotal;
            }
        }

        foreach ($actual as $key => $amount) {
            if (! array_key_exists($key, $expected) ||
                (int) round($amount * 100) !== (int) round((float) $expected[$key] * 100)) {
                // This rollback also restores the cart, stock, coupon, and linked delivery.
                throw new ConflictHttpException('QUOTE_CHANGED');
            }
        }

        DB::table('commerce_checkout_quotes')
            ->where('id', $quote->id)
            ->update(['consumed_order_id' => $order->getKey(), 'updated_at' => now()]);
    }

    private function fingerprintContext(int $userId, string $section, int $cartId): array
    {
        if ($section === 'restaurant') {
            $cart = Cart::query()->whereKey($cartId)->where('user_id', $userId)
                ->whereNotNull('restaurant_id')->lockForUpdate()->firstOrFail();
            $pricing = app(RestaurantCartPricingService::class)->price($cart, true);
            $fingerprint = hash('sha256', json_encode([$cart->restaurant_id, $pricing->fingerprint], JSON_THROW_ON_ERROR));

            return [$fingerprint, $pricing, $cart];
        } elseif ($section === 'supermarket') {
            $cart = SmCart::query()->whereKey($cartId)->where('user_id', $userId)
                ->with('items.product')->firstOrFail();

            $lines = $cart->items->sortBy('id')->map(function ($item): array {
                $modifierIds = array_map('intval', $item->modifier_ids ?? []);
                sort($modifierIds);

                return [
                    'id' => (int) $item->id,
                    'productId' => (int) $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (string) $item->unit_price,
                    'productPrice' => (string) $item->product?->price,
                    'productDiscountedPrice' => $item->product?->discounted_price === null ? null : (string) $item->product->discounted_price,
                    'productAvailable' => (bool) $item->product?->is_available,
                    'productStock' => $item->product?->stock_quantity,
                    'modifierIds' => $modifierIds,
                    'note' => $item->note,
                    'substituteProductId' => $item->substitute_product_id,
                ];
            })->values()->all();
        } else {
            throw new InvalidArgumentException('Unsupported commerce section');
        }

        return [hash('sha256', json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), null, null];
    }

    /**
     * MySQL JSON objects may be returned with keys in a different order than
     * the request array. Keep strict value/type comparison while ignoring
     * object key order.
     *
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    private function canonicalSelection(array $selection): array
    {
        ksort($selection);

        return $selection;
    }
}