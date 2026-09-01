<?php

declare(strict_types=1);

namespace Modules\User\Support;

final class UserRestaurantCartPayload
{
    /**
     * Enrich a cart payload with pricing that is meaningful to the customer:
     * subtotal = price before active product offers,
     * discount = savings from active product offers,
     * total = amount after those offers.
     *
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    public static function normalize(array $cart): array
    {
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];

        $originalSubtotal = 0.0;
        $discountedTotal = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $totalPrice = max(0.0, (float) ($item['totalPrice'] ?? 0));
            $originalTotal = array_key_exists('originalTotalPrice', $item) && $item['originalTotalPrice'] !== null
                ? max($totalPrice, (float) $item['originalTotalPrice'])
                : $totalPrice;

            $discountedTotal += $totalPrice;
            $originalSubtotal += $originalTotal;
        }

        $discount = max(0.0, $originalSubtotal - $discountedTotal);
        $discountPercent = $originalSubtotal > 0
            ? ($discount / $originalSubtotal) * 100
            : 0.0;

        $cart['amounts'] = array_merge(
            is_array($cart['amounts'] ?? null) ? $cart['amounts'] : [],
            [
                'subtotal' => round($originalSubtotal, 2),
                'discount' => round($discount, 2),
                'discountPercent' => round($discountPercent, 2),
                'total' => round($discountedTotal, 2),
            ],
        );

        return $cart;
    }

    /**
     * @param  array<int, array<string, mixed>>  $carts
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeMany(array $carts): array
    {
        return array_values(array_map(
            static fn (array $cart): array => self::normalize($cart),
            $carts,
        ));
    }
}
