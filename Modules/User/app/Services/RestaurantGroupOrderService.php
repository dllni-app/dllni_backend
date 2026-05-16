<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Support\Broadcast\BroadcastAfterResponse;
use App\Services\DeepLinks\CanonicalDeepLinkGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantGroupOrderParticipantStatus;
use Modules\Resturants\Enums\RestaurantGroupOrderStatus;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderItem;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;
use Modules\User\Events\RestaurantGroupOrderUpdated;

final class RestaurantGroupOrderService
{
    private CanonicalDeepLinkGenerator $deepLinkGenerator;

    public function __construct(
        CanonicalDeepLinkGenerator $deepLinkGenerator,
    ) {
        $this->deepLinkGenerator = $deepLinkGenerator;
    }

    public function create(
        int $organizerUserId,
        int $restaurantId,
        ?string $name,
        int $durationMinutes,
    ): RestaurantGroupOrder {
        $hasActive = RestaurantGroupOrder::query()
            ->where('user_id', $organizerUserId)
            ->where('status', RestaurantGroupOrderStatus::Active)
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'groupOrder' => ['You already have an active group order.'],
            ]);
        }

        return DB::transaction(function () use ($organizerUserId, $restaurantId, $name, $durationMinutes): RestaurantGroupOrder {
            $order = RestaurantGroupOrder::query()->create([
                'user_id' => $organizerUserId,
                'restaurant_id' => $restaurantId,
                'name' => $name,
                'share_token' => Str::lower(Str::random(32)),
                'delivery_fee_strategy' => 'organizer_pays',
                'status' => RestaurantGroupOrderStatus::Active,
                'ends_at' => now()->addMinutes($durationMinutes),
            ]);

            RestaurantGroupOrderParticipant::query()->create([
                'group_order_id' => $order->id,
                'user_id' => $organizerUserId,
                'status' => RestaurantGroupOrderParticipantStatus::Joined,
            ]);

            return $order->fresh();
        });
    }

    public function joinByToken(string $shareToken, int $userId): RestaurantGroupOrder
    {
        $order = RestaurantGroupOrder::query()
            ->where('share_token', $shareToken)
            ->firstOrFail();

        $this->finalizeIfExpired($order);
        $order->refresh();

        if ($order->status !== RestaurantGroupOrderStatus::Active) {
            throw ValidationException::withMessages([
                'groupOrder' => ['This group order is no longer active.'],
            ]);
        }

        RestaurantGroupOrderParticipant::query()->firstOrCreate([
            'group_order_id' => $order->id,
            'user_id' => $userId,
        ], [
            'status' => RestaurantGroupOrderParticipantStatus::Joined,
        ]);

        return $order->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeOrdersForUser(int $userId): array
    {
        $orders = RestaurantGroupOrder::query()
            ->where('status', RestaurantGroupOrderStatus::Active)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhereHas('participants', fn($q) => $q->where('user_id', $userId));
            })
            ->orderBy('ends_at')
            ->get();

        $payloads = [];

        foreach ($orders as $order) {
            $this->finalizeIfExpired($order);
            $order->refresh();

            if ($order->status === RestaurantGroupOrderStatus::Active) {
                $payloads[] = $this->publicPayload($order, $userId);
            }
        }

        return $payloads;
    }

    public function addItem(
        RestaurantGroupOrder $groupOrder,
        int $userId,
        int $productId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): RestaurantGroupOrderItem {
        return DB::transaction(function () use ($groupOrder, $userId, $productId, $quantity, $modifierIds, $substituteProductId, $note): RestaurantGroupOrderItem {
            $groupOrder = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();
            $this->finalizeIfExpired($groupOrder);
            $groupOrder->refresh();
            $this->assertActive($groupOrder);

            $participant = $this->resolveParticipant($groupOrder, $userId);

            $product = Product::query()
                ->with(['modifierGroups.modifiers'])
                ->findOrFail($productId);

            if ((int) $product->restaurant_id !== (int) $groupOrder->restaurant_id) {
                throw ValidationException::withMessages([
                    'productId' => ['Product must belong to the group order restaurant.'],
                ]);
            }

            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = (float) $modifiers->sum(fn(Modifier $modifier): float => (float) ($modifier->price ?? 0));
            $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $unitPrice = $basePrice + $modifierTotal;

            $item = RestaurantGroupOrderItem::query()->create([
                'group_order_id' => $groupOrder->id,
                'participant_id' => $participant->id,
                'product_id' => $product->id,
                'substitute_product_id' => $substituteProductId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $note,
            ]);

            if ($modifiers->isNotEmpty()) {
                $item->modifiers()->attach(
                    $modifiers->mapWithKeys(fn(Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            if ($participant->status === RestaurantGroupOrderParticipantStatus::Submitted) {
                $participant->update([
                    'status' => RestaurantGroupOrderParticipantStatus::Joined,
                    'submitted_at' => null,
                ]);
            }

            return $item;
        });
    }

    public function updateItem(
        RestaurantGroupOrder $groupOrder,
        int $userId,
        int $itemId,
        int $quantity,
        array $modifierIds = [],
        ?int $substituteProductId = null,
        ?string $note = null,
    ): RestaurantGroupOrderItem {
        return DB::transaction(function () use ($groupOrder, $userId, $itemId, $quantity, $modifierIds, $substituteProductId, $note): RestaurantGroupOrderItem {
            $groupOrder = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();
            $this->finalizeIfExpired($groupOrder);
            $groupOrder->refresh();
            $this->assertActive($groupOrder);

            $participant = $this->resolveParticipant($groupOrder, $userId, false);

            $item = RestaurantGroupOrderItem::query()
                ->whereKey($itemId)
                ->where('group_order_id', $groupOrder->id)
                ->where('participant_id', $participant->id)
                ->with(['product.modifierGroups.modifiers'])
                ->firstOrFail();

            $product = $item->product;
            $modifiers = $this->validatedModifiers($product, $modifierIds);
            $modifierTotal = (float) $modifiers->sum(fn(Modifier $modifier): float => (float) ($modifier->price ?? 0));
            $basePrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $unitPrice = $basePrice + $modifierTotal;

            $item->update([
                'quantity' => $quantity,
                'substitute_product_id' => $substituteProductId,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'special_instructions' => $note,
            ]);

            $item->modifiers()->detach();
            if ($modifiers->isNotEmpty()) {
                $item->modifiers()->attach(
                    $modifiers->mapWithKeys(fn(Modifier $modifier): array => [
                        $modifier->id => ['price' => (float) ($modifier->price ?? 0)],
                    ])->all()
                );
            }

            if ($participant->status === RestaurantGroupOrderParticipantStatus::Submitted) {
                $participant->update([
                    'status' => RestaurantGroupOrderParticipantStatus::Joined,
                    'submitted_at' => null,
                ]);
            }

            return $item;
        });
    }

    public function deleteItem(RestaurantGroupOrder $groupOrder, int $userId, int $itemId): void
    {
        DB::transaction(function () use ($groupOrder, $userId, $itemId): void {
            $groupOrder = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();
            $this->finalizeIfExpired($groupOrder);
            $groupOrder->refresh();
            $this->assertActive($groupOrder);

            $participant = $this->resolveParticipant($groupOrder, $userId, false);

            $item = RestaurantGroupOrderItem::query()
                ->whereKey($itemId)
                ->where('group_order_id', $groupOrder->id)
                ->where('participant_id', $participant->id)
                ->firstOrFail();

            $item->delete();

            if ($participant->status === RestaurantGroupOrderParticipantStatus::Submitted) {
                $participant->update([
                    'status' => RestaurantGroupOrderParticipantStatus::Joined,
                    'submitted_at' => null,
                ]);
            }
        });
    }

    public function setParticipantSubmission(RestaurantGroupOrder $groupOrder, int $userId, bool $submitted): RestaurantGroupOrder
    {
        DB::transaction(function () use ($groupOrder, $userId, $submitted): void {
            $locked = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();
            $this->finalizeIfExpired($locked);
            $locked->refresh();
            $this->assertActive($locked);

            $participant = $this->resolveParticipant($locked, $userId, false);

            $itemCount = RestaurantGroupOrderItem::query()
                ->where('group_order_id', $locked->id)
                ->where('participant_id', $participant->id)
                ->count();

            if ($submitted && $itemCount === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Add at least one item before confirming participation.'],
                ]);
            }

            $participant->update([
                'status' => $submitted
                    ? RestaurantGroupOrderParticipantStatus::Submitted
                    : RestaurantGroupOrderParticipantStatus::Joined,
                'submitted_at' => $submitted ? now() : null,
            ]);

            if ($submitted) {
                $this->attemptPlacement($locked, false);
            }
        });

        return $groupOrder->fresh();
    }

    public function cancel(RestaurantGroupOrder $groupOrder, int $actorUserId): RestaurantGroupOrder
    {
        DB::transaction(function () use ($groupOrder, $actorUserId): void {
            $locked = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertOrganizer($locked, $actorUserId);

            if ($locked->status !== RestaurantGroupOrderStatus::Active) {
                throw ValidationException::withMessages([
                    'groupOrder' => ['Only active group orders can be cancelled.'],
                ]);
            }

            $locked->update([
                'status' => RestaurantGroupOrderStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
        });

        return $groupOrder->fresh();
    }

    public function placeNow(RestaurantGroupOrder $groupOrder, ?int $actorUserId = null): RestaurantGroupOrder
    {
        DB::transaction(function () use ($groupOrder, $actorUserId): void {
            $locked = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->firstOrFail();

            if ($actorUserId !== null) {
                $this->assertOrganizer($locked, $actorUserId);
            }

            $this->attemptPlacement($locked, true);
        });

        return $groupOrder->fresh();
    }

    public function finalizeIfExpired(RestaurantGroupOrder $groupOrder): void
    {
        if ($groupOrder->status !== RestaurantGroupOrderStatus::Active) {
            return;
        }

        if (now()->lessThanOrEqualTo($groupOrder->ends_at)) {
            return;
        }

        DB::transaction(function () use ($groupOrder): void {
            $locked = RestaurantGroupOrder::query()->whereKey($groupOrder->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $before = $locked->status;

            $this->attemptPlacement($locked, true);

            $locked->refresh();

            if ($before !== $locked->status) {
                $this->dispatchUpdate($locked);
            }
        });
    }

    public function processExpiredActiveOrders(): int
    {
        $ids = RestaurantGroupOrder::query()
            ->where('status', RestaurantGroupOrderStatus::Active)
            ->where('ends_at', '<=', now())
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            $groupOrder = RestaurantGroupOrder::query()->find($id);
            if ($groupOrder) {
                $this->finalizeIfExpired($groupOrder);
            }
        }

        return count($ids);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(RestaurantGroupOrder $groupOrder, int $currentUserId): array
    {
        $this->finalizeIfExpired($groupOrder);

        $groupOrder->refresh();
        $groupOrder->load([
            'restaurant',
            'organizer:id,name',
            'participants.user:id,name',
            'participants.items.product:id,name',
            'participants.items.modifiers:id,name',
            'placedOrder',
        ]);

        $this->assertParticipantOrOrganizer($groupOrder, $currentUserId);

        $participantRows = $groupOrder->participants
            ->sortBy('id')
            ->values()
            ->map(function (RestaurantGroupOrderParticipant $participant): array {
                $subtotal = (float) $participant->items->sum(fn(RestaurantGroupOrderItem $item): float => (float) ($item->total_price ?? 0));

                $items = $participant->items
                    ->sortBy('id')
                    ->values()
                    ->map(function (RestaurantGroupOrderItem $item): array {
                        return [
                            'id' => $item->id,
                            'productId' => $item->product_id,
                            'name' => $item->product?->name,
                            'quantity' => $item->quantity,
                            'unitPrice' => (float) ($item->unit_price ?? 0),
                            'totalPrice' => (float) ($item->total_price ?? 0),
                            'modifierIds' => $item->modifiers->pluck('id')->map(fn($id): int => (int) $id)->values()->all(),
                            'note' => $item->special_instructions,
                        ];
                    })
                    ->all();

                return [
                    'participantId' => $participant->id,
                    'userId' => $participant->user_id,
                    'name' => $participant->user?->name,
                    'status' => $participant->status->value,
                    'hasResponded' => $participant->status === RestaurantGroupOrderParticipantStatus::Submitted,
                    'submittedAt' => $participant->submitted_at?->toIso8601String(),
                    'subtotal' => round($subtotal, 2),
                    'itemsCount' => count($items),
                    'items' => $items,
                ];
            })
            ->all();

        $participantsCount = count($participantRows);
        $respondedCount = (int) collect($participantRows)->where('hasResponded', true)->count();
        $itemsCount = (int) collect($participantRows)->sum('itemsCount');
        $subtotal = (float) collect($participantRows)->sum('subtotal');

        $secondsRemaining = 0;
        if ($groupOrder->status === RestaurantGroupOrderStatus::Active) {
            $secondsRemaining = max(0, (int) ($groupOrder->ends_at->getTimestamp() - now()->getTimestamp()));
        }

        return [
            'groupOrder' => [
                'id' => $groupOrder->id,
                'name' => $groupOrder->name,
                'status' => $groupOrder->status->value,
                'restaurantId' => $groupOrder->restaurant_id,
                'restaurantName' => $groupOrder->restaurant?->name,
                'shareToken' => $groupOrder->share_token,
                'shareUrl' => $this->deepLinkGenerator->groupOrder((string) $groupOrder->share_token),
                'deliveryFeeStrategy' => $groupOrder->delivery_fee_strategy,
                'endsAt' => $groupOrder->ends_at->toIso8601String(),
                'secondsRemaining' => $secondsRemaining,
                'creatorUserId' => $groupOrder->user_id,
                'isCreator' => $currentUserId === (int) $groupOrder->user_id,
                'placedOrderId' => $groupOrder->placed_order_id,
                'placedAt' => $groupOrder->placed_at?->toIso8601String(),
                'createdAt' => $groupOrder->created_at?->toIso8601String(),
            ],
            'participants' => $participantRows,
            'counts' => [
                'participants' => $participantsCount,
                'responded' => $respondedCount,
                'pending' => max(0, $participantsCount - $respondedCount),
                'items' => $itemsCount,
            ],
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'deliveryFee' => 0.0,
                'total' => round($subtotal, 2),
            ],
        ];
    }

    private function assertActive(RestaurantGroupOrder $groupOrder): void
    {
        if ($groupOrder->status !== RestaurantGroupOrderStatus::Active) {
            throw ValidationException::withMessages([
                'groupOrder' => ['This group order is no longer active.'],
            ]);
        }
    }

    private function assertOrganizer(RestaurantGroupOrder $groupOrder, int $actorUserId): void
    {
        if ((int) $groupOrder->user_id !== $actorUserId) {
            throw ValidationException::withMessages([
                'groupOrder' => ['Only the organizer can perform this action.'],
            ]);
        }
    }

    private function assertParticipantOrOrganizer(RestaurantGroupOrder $groupOrder, int $currentUserId): void
    {
        if ((int) $groupOrder->user_id === $currentUserId) {
            return;
        }

        $isParticipant = RestaurantGroupOrderParticipant::query()
            ->where('group_order_id', $groupOrder->id)
            ->where('user_id', $currentUserId)
            ->exists();

        if (! $isParticipant) {
            throw ValidationException::withMessages([
                'groupOrder' => ['You are not a participant in this group order.'],
            ]);
        }
    }

    private function resolveParticipant(RestaurantGroupOrder $groupOrder, int $userId, bool $createIfMissing = true): RestaurantGroupOrderParticipant
    {
        $participant = RestaurantGroupOrderParticipant::query()
            ->where('group_order_id', $groupOrder->id)
            ->where('user_id', $userId)
            ->first();

        if ($participant) {
            return $participant;
        }

        if (! $createIfMissing) {
            throw ValidationException::withMessages([
                'groupOrder' => ['Join this group order first.'],
            ]);
        }

        return RestaurantGroupOrderParticipant::query()->create([
            'group_order_id' => $groupOrder->id,
            'user_id' => $userId,
            'status' => RestaurantGroupOrderParticipantStatus::Joined,
        ]);
    }

    /**
     * @param  array<int>  $modifierIds
     * @return \Illuminate\Support\Collection<int, Modifier>
     */
    private function validatedModifiers(Product $product, array $modifierIds)
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));

        if ($modifierIds === []) {
            return collect();
        }

        $allowedIds = $product->modifierGroups
            ->flatMap(fn($group) => $group->modifiers->pluck('id'))
            ->unique()
            ->values()
            ->all();

        if (array_diff($modifierIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'modifierIds' => ['Some modifiers are not allowed for this product.'],
            ]);
        }

        return Modifier::query()->whereIn('id', $modifierIds)->get();
    }

    private function attemptPlacement(RestaurantGroupOrder $groupOrder, bool $force): void
    {
        if (
            $groupOrder->status === RestaurantGroupOrderStatus::Placed
            || $groupOrder->status === RestaurantGroupOrderStatus::Cancelled
            || $groupOrder->status === RestaurantGroupOrderStatus::Expired
        ) {
            return;
        }

        $groupOrder->load(['participants', 'items.modifiers']);

        $participantsCount = $groupOrder->participants->count();
        $allSubmitted = $participantsCount > 0
            && $groupOrder->participants->every(
                fn(RestaurantGroupOrderParticipant $participant): bool =>
                $participant->status === RestaurantGroupOrderParticipantStatus::Submitted
            );

        $canPlace = $force || $allSubmitted;

        if (! $canPlace) {
            return;
        }

        if ($groupOrder->items->isEmpty()) {
            $groupOrder->update([
                'status' => RestaurantGroupOrderStatus::Expired,
            ]);

            return;
        }

        $groupOrder->update([
            'status' => RestaurantGroupOrderStatus::Placing,
        ]);

        $subtotal = (float) $groupOrder->items->sum(fn(RestaurantGroupOrderItem $item): float => (float) ($item->total_price ?? 0));
        $totalAmount = $subtotal;

        $order = Order::query()->create([
            'user_id' => $groupOrder->user_id,
            'restaurant_id' => $groupOrder->restaurant_id,
            'promo_code_id' => null,
            'order_number' => 'GRP-' . mb_strtoupper(Str::random(8)) . '-' . random_int(1000, 9999),
            'status' => OrderStatus::Pending->value,
            'order_type' => OrderType::Delivery->value,
            'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
            'pickup_scheduled_for' => null,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_fee' => 0,
            'total_amount' => $totalAmount,
            'special_instructions' => 'Generated from group order #' . $groupOrder->id,
        ]);

        foreach ($groupOrder->items as $groupItem) {
            $orderItem = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $groupItem->product_id,
                'substitute_product_id' => $groupItem->substitute_product_id,
                'quantity' => $groupItem->quantity,
                'unit_price' => $groupItem->unit_price,
                'total_price' => $groupItem->total_price,
                'special_instructions' => $groupItem->special_instructions,
            ]);

            $modifierRows = DB::table('restaurant_group_order_item_modifier')
                ->where('group_order_item_id', $groupItem->id)
                ->get(['modifier_id', 'price'])
                ->map(fn($row): array => [
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

        OrderStatusLog::query()->firstOrCreate([
            'order_id' => $order->id,
            'to_status' => OrderStatus::Pending->value,
        ], [
            'from_status' => null,
            'note' => 'Order placed by group order flow.',
        ]);

        $groupOrder->update([
            'status' => RestaurantGroupOrderStatus::Placed,
            'placed_order_id' => $order->id,
            'placed_at' => now(),
        ]);
    }

    private function dispatchUpdate(RestaurantGroupOrder $groupOrder): void
    {
        $payload = $this->publicPayload($groupOrder, (int) $groupOrder->user_id);
        BroadcastAfterResponse::send(new RestaurantGroupOrderUpdated($groupOrder, $payload));
    }
}
