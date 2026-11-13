<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;

/** Compatibility facade: there is one restaurant cart mutation and pricing path. */
final class RestaurantCartService
{
    public function __construct(private readonly UserRestaurantCartService $carts) {}

    /** @return array{cart: Cart, item: CartItem} */
    public function addProductToCart(
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $specialInstructions = null,
    ): array {
        $result = $this->carts->addItem($userId, $productId, $quantity, $modifierIds, $substituteProductId, $specialInstructions);

        return [
            'cart' => Cart::query()->where('user_id', $userId)->findOrFail($result['cartId']),
            'item' => CartItem::query()->where('cart_id', $result['cartId'])->findOrFail($result['itemId']),
        ];
    }
}
