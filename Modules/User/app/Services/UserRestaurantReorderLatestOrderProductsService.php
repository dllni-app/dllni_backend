<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;

final class UserRestaurantReorderLatestOrderProductsService
{
    public function __construct(
        private RestaurantCartService $cartService,
    ) {}

    /**
     * @return array{cartId:int, itemIds:array<int, int>, itemsAdded:int}
     */
    public function reorderLatestOrderProducts(int $userId): array
    {
        $latestOrder = Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->with([
                'orderItems.product.restaurant',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestOrder === null) {
            throw ValidationException::withMessages([
                'order' => ['No previous order found for reordering.'],
            ]);
        }

        $orderItems = $latestOrder->orderItems
            ->filter(function ($item): bool {
                return $item->product !== null
                    && $item->product->is_available
                    && $item->product->restaurant !== null
                    && $item->product->restaurant->is_active;
            })
            ->values();

        if ($orderItems->isEmpty()) {
            throw ValidationException::withMessages([
                'order' => ['No reorderable products found in your latest order.'],
            ]);
        }

        $addedItems = [];
        $cartId = null;

        foreach ($orderItems as $orderItem) {
            $modifierIds = DB::table('order_item_modifier')
                ->where('order_item_id', $orderItem->id)
                ->pluck('modifier_id')
                ->map(fn(mixed $value): int => (int) $value)
                ->all();

            try {
                $result = $this->cartService->addProductToCart(
                    userId: $userId,
                    productId: (int) $orderItem->product_id,
                    quantity: (int) $orderItem->quantity,
                    modifierIds: $modifierIds,
                    substituteProductId: $orderItem->substitute_product_id !== null
                        ? (int) $orderItem->substitute_product_id
                        : null,
                    specialInstructions: $orderItem->special_instructions,
                );
            } catch (ValidationException $exception) {
                if (! $this->hasModifierValidationError($exception)) {
                    throw $exception;
                }

                $result = $this->cartService->addProductToCart(
                    userId: $userId,
                    productId: (int) $orderItem->product_id,
                    quantity: (int) $orderItem->quantity,
                    modifierIds: [],
                    substituteProductId: $orderItem->substitute_product_id !== null
                        ? (int) $orderItem->substitute_product_id
                        : null,
                    specialInstructions: $orderItem->special_instructions,
                );
            }

            $cartId ??= (int) $result['cart']->id;
            $addedItems[] = (int) $result['item']->id;
        }

        return [
            'cartId' => $cartId ?? 0,
            'itemIds' => $addedItems,
            'itemsAdded' => count($addedItems),
        ];
    }

    private function hasModifierValidationError(ValidationException $exception): bool
    {
        return array_key_exists('modifierIds', $exception->errors());
    }
}
