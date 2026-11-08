<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RestaurantV2ReorderService
{
    public function __construct(private readonly UserRestaurantCartService $carts) {}

    /** @param array<string,mixed> $input */
    public function preview(int $userId, int $orderId, array $input): array
    {
        $order = $this->order($userId, $orderId);
        $items = $order->orderItems->keyBy('id');
        $requested = array_key_exists('lines', $input) ? $input['lines'] : $order->orderItems->map(fn ($item): array => ['orderItemId' => $item->id])->all();
        $cart = $this->cart($userId, (int) $order->restaurant_id);
        $lines = [];
        $allValid = $requested !== [];

        foreach ($requested as $choice) {
            $item = $items->get($choice['orderItemId']);
            if ($item === null) {
                throw ValidationException::withMessages(['lines' => 'Order item does not belong to this order.']);
            }
            $line = $this->line($order, $item, $choice);
            $lines[] = $line;
            $allValid = $allValid && in_array($line['status'], ['available', 'price_changed'], true);
        }

        $configurations = array_map(static fn (array $line): array => [
            'orderItemId' => $line['orderItemId'], 'productId' => $line['productId'],
            'quantity' => $line['quantity'], 'modifierIds' => $line['modifierIds'],
            'substituteProductId' => $line['substituteProductId'], 'note' => $line['note'],
        ], $lines);
        $token = $allValid ? Crypt::encryptString(json_encode([
            'userId' => $userId, 'orderId' => $orderId, 'merchantId' => (int) $order->restaurant_id,
            'cartId' => $cart?->id, 'revision' => (int) ($cart?->revision ?? 0),
            'lines' => $configurations, 'fingerprint' => $this->fingerprint($lines),
            'expiresAt' => CarbonImmutable::now()->addMinutes(5)->timestamp,
        ], JSON_THROW_ON_ERROR)) : null;

        return ['sourceOrderId' => $orderId, 'merchant' => ['id' => (int) $order->restaurant_id, 'name' => $order->restaurant?->name],
            'currency' => 'SYP', 'cart' => ['id' => $cart?->id, 'revision' => (int) ($cart?->revision ?? 0)],
            'lines' => $lines, 'canApply' => $allValid, 'previewToken' => $token,
            'expiresAt' => $allValid ? CarbonImmutable::now()->addMinutes(5)->toIso8601String() : null];
    }

    public function apply(int $userId, int $orderId, string $previewToken): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($previewToken), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            throw ValidationException::withMessages(['previewToken' => 'Invalid reorder preview.']);
        }
        if (! is_array($payload) || ($payload['userId'] ?? null) !== $userId || ($payload['orderId'] ?? null) !== $orderId || ! is_array($payload['lines'] ?? null)) {
            throw ValidationException::withMessages(['previewToken' => 'Invalid reorder preview.']);
        }
        if (! is_int($payload['expiresAt'] ?? null) || CarbonImmutable::now()->timestamp >= $payload['expiresAt']) {
            throw new ConflictHttpException('REORDER_PREVIEW_EXPIRED');
        }

        return DB::transaction(function () use ($userId, $orderId, $payload): array {
            // A user lock serializes concurrent first-cart creation for reviewed reorders.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $order = $this->order($userId, $orderId);
            if ((int) $order->restaurant_id !== ($payload['merchantId'] ?? null)) {
                throw new ConflictHttpException('REORDER_REVIEW_REQUIRED');
            }
            $cart = Cart::query()->where('user_id', $userId)->where('restaurant_id', $order->restaurant_id)->lockForUpdate()->first();
            if ($cart?->id !== ($payload['cartId'] ?? null) || (int) ($cart?->revision ?? 0) !== ($payload['revision'] ?? null)) {
                throw new ConflictHttpException('CART_CONFLICT');
            }
            $items = $order->orderItems->keyBy('id');
            $reviewed = [];
            foreach ($payload['lines'] as $choice) {
                if (! is_array($choice) || ! isset($choice['orderItemId']) || ! $items->has($choice['orderItemId'])) {
                    throw new ConflictHttpException('REORDER_REVIEW_REQUIRED');
                }
                $line = $this->line($order, $items->get($choice['orderItemId']), $choice);
                if (! in_array($line['status'], ['available', 'price_changed'], true)) {
                    throw new ConflictHttpException('REORDER_REVIEW_REQUIRED');
                }
                $reviewed[] = $line;
            }
            if ($reviewed === [] || ! hash_equals((string) ($payload['fingerprint'] ?? ''), $this->fingerprint($reviewed))) {
                throw new ConflictHttpException('REORDER_REVIEW_REQUIRED');
            }
            $cartId = null;
            foreach ($reviewed as $line) {
                $result = $this->carts->addItem(
                    userId: $userId, productId: $line['productId'], quantity: $line['quantity'],
                    modifierIds: $line['modifierIds'], substituteProductId: $line['substituteProductId'],
                    note: $line['note'], quantityMode: 'increment',
                );
                $cartId = $result['cartId'];
            }

            return ['cart' => $this->carts->show($userId, $cartId), 'itemsAdded' => count($reviewed), 'sourceOrderId' => $orderId];
        });
    }

    private function order(int $userId, int $orderId): Order
    {
        return Order::query()->where('user_id', $userId)->with(['restaurant', 'orderItems'])->findOrFail($orderId);
    }

    private function cart(int $userId, int $merchantId): ?Cart
    {
        return Cart::query()->where('user_id', $userId)->where('restaurant_id', $merchantId)->first();
    }

    /** @param array<string,mixed> $choice */
    private function line(Order $order, $item, array $choice): array
    {
        $productId = (int) ($choice['productId'] ?? $item->product_id);
        $quantity = (int) ($choice['quantity'] ?? $item->quantity);
        $modifierIds = $choice['modifierIds'] ?? DB::table('order_item_modifier')->where('order_item_id', $item->id)->pluck('modifier_id')->map(fn ($id): int => (int) $id)->all();
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
        sort($modifierIds);
        $product = Product::query()->with('modifierGroups.modifiers')->find($productId);
        $issues = [];
        if (! $order->restaurant?->is_active || $product === null || (int) $product->restaurant_id !== (int) $order->restaurant_id || ! $product->isAvailableNow()) {
            $issues[] = ['code' => 'PRODUCT_UNAVAILABLE'];
        }
        if ($quantity < 1 || $quantity > 50) {
            $issues[] = ['code' => 'QUANTITY_OUT_OF_RANGE'];
        }
        $note = array_key_exists('note', $choice) ? $choice['note'] : $item->special_instructions;
        if (mb_strlen((string) $note) > 1000) {
            $issues[] = ['code' => 'NOTE_TOO_LONG'];
        }
        $substituteId = array_key_exists('substituteProductId', $choice) ? $choice['substituteProductId'] : $item->substitute_product_id;
        if ($substituteId !== null && ! Product::query()->whereKey($substituteId)->where('restaurant_id', $order->restaurant_id)->exists()) {
            $issues[] = ['code' => 'SUBSTITUTE_UNAVAILABLE'];
        }
        $validIds = $product?->modifierGroups->flatMap(fn ($group) => $group->modifiers->pluck('id'))->map(fn ($id): int => (int) $id)->all() ?? [];
        foreach (array_diff($modifierIds, $validIds) as $missing) {
            $issues[] = ['code' => 'MODIFIER_UNAVAILABLE', 'modifierId' => $missing];
        }
        if ($product !== null) {
            foreach ($product->modifierGroups as $group) {
                $selected = $group->modifiers->pluck('id')->intersect($modifierIds)->count();
                if ($selected < (int) $group->min_selections || $selected > (int) $group->max_selections || ($group->is_required && $selected === 0)) {
                    $issues[] = ['code' => 'CONFIGURATION_REQUIRED', 'groupId' => (int) $group->id];
                }
            }
        }
        $selectedModifiers = $product?->modifierGroups->flatMap(fn ($group) => $group->modifiers)->whereIn('id', $modifierIds) ?? collect();
        $currentUnit = $issues === [] ? $product->effectivePrice() + (float) $selectedModifiers->sum('price') : null;
        $oldUnit = $item->unit_price !== null ? (float) $item->unit_price : null;
        $status = $issues !== [] ? (collect($issues)->contains(fn (array $issue): bool => $issue['code'] === 'PRODUCT_UNAVAILABLE') ? 'unavailable' : 'requires_configuration')
            : ($oldUnit !== null && round($oldUnit, 2) !== round($currentUnit, 2) ? 'price_changed' : 'available');

        return ['orderItemId' => (int) $item->id, 'productId' => $productId, 'quantity' => $quantity,
            'modifierIds' => $modifierIds, 'substituteProductId' => $substituteId,
            'note' => $note,
            'purchased' => ['name' => $item->product_name, 'unitPrice' => $oldUnit === null ? null : number_format($oldUnit, 2, '.', ''),
                'lineTotal' => $item->total_price === null ? null : number_format((float) $item->total_price, 2, '.', ''), 'modifierSnapshot' => $item->modifier_snapshot ?? []],
            'current' => ['name' => $product?->name, 'unitPrice' => $currentUnit === null ? null : number_format($currentUnit, 2, '.', ''),
                'estimatedLineTotal' => $currentUnit === null ? null : number_format($currentUnit * $quantity, 2, '.', ''),
                'modifierSnapshot' => $selectedModifiers->map(fn ($m): array => ['id' => (int) $m->id, 'name' => $m->name, 'price' => number_format((float) $m->price, 2, '.', '')])->values()->all(),
                'isAvailable' => $issues === []],
            'status' => $status, 'issues' => $issues];
    }

    private function fingerprint(array $lines): string
    {
        return hash('sha256', json_encode(array_map(static fn (array $line): array => [
            'orderItemId' => $line['orderItemId'], 'productId' => $line['productId'], 'quantity' => $line['quantity'],
            'modifierIds' => $line['modifierIds'], 'substituteProductId' => $line['substituteProductId'], 'note' => $line['note'],
            'status' => $line['status'], 'current' => $line['current'],
        ], $lines), JSON_THROW_ON_ERROR));
    }
}
