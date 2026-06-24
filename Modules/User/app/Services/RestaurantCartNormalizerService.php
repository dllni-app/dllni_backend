<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;

final class RestaurantCartNormalizerService
{
    private const CART_PAYLOAD_RELATIONS = [
        'items.product.restaurant.media',
        'items.product.media',
        'items.modifiers',
    ];

    public function normalize(Cart $cart): Cart
    {
        return DB::transaction(function () use ($cart): Cart {
            $lockedCart = Cart::query()
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->firstOrFail();

            $items = CartItem::query()
                ->where('cart_id', $lockedCart->id)
                ->with('modifiers')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $lockedCart->fresh(self::CART_PAYLOAD_RELATIONS) ?? $lockedCart;
            }

            $rows = $items->map(function (CartItem $item): array {
                $modifierIds = $item->modifiers
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $signatureHash = $this->signatureHash(
                    productId: (int) $item->product_id,
                    modifierIds: $modifierIds,
                    substituteProductId: $item->substitute_product_id === null ? null : (int) $item->substitute_product_id,
                    note: $this->normalizeNote($item->special_instructions),
                );

                return [
                    'item' => $item,
                    'signature_hash' => $signatureHash,
                    'quantity' => max(1, (int) $item->quantity),
                ];
            });

            foreach ($rows->groupBy('signature_hash') as $signatureHash => $group) {
                $keeperRow = $group->first(
                    fn (array $row): bool => (string) $row['item']->signature_hash === (string) $signatureHash,
                ) ?? $group->first();

                /** @var CartItem $keeper */
                $keeper = $keeperRow['item'];
                $mergedQuantity = (int) $group->sum('quantity');
                $unitPrice = (float) ($keeper->unit_price ?? 0);
                $normalizedNote = $this->normalizeNote($keeper->special_instructions);

                foreach ($group as $row) {
                    /** @var CartItem $item */
                    $item = $row['item'];

                    if ((int) $item->id === (int) $keeper->id) {
                        continue;
                    }

                    $item->modifiers()->detach();
                    $item->delete();
                }

                $keeper->forceFill([
                    'quantity' => $mergedQuantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $mergedQuantity,
                    'signature_hash' => $signatureHash,
                    'special_instructions' => $normalizedNote,
                ])->save();
            }

            return $lockedCart->fresh(self::CART_PAYLOAD_RELATIONS) ?? $lockedCart;
        });
    }

    /**
     * @param  array<int>  $modifierIds
     */
    private function signatureHash(int $productId, array $modifierIds, ?int $substituteProductId, ?string $note): string
    {
        return hash('sha256', json_encode([
            'product_id' => $productId,
            'modifier_ids' => $this->normalizeModifierIds($modifierIds),
            'substitute_product_id' => $substituteProductId,
            'note' => $this->normalizeNote($note),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int>  $modifierIds
     * @return array<int>
     */
    private function normalizeModifierIds(array $modifierIds): array
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
        sort($modifierIds);

        return $modifierIds;
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($note));

        return $normalized === '' ? null : $normalized;
    }
}
