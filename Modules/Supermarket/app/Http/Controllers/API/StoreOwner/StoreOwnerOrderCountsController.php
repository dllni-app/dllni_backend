<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerOrderCountsController
{
    public function __construct(private readonly StoreOwnerContextService $context) {}

    public function __invoke(): JsonResponse
    {
        $storeId = $this->context->ownedStore()->id;

        $counts = SmOrder::query()
            ->where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count): int => (int) $count);

        $pending = $counts->get(SmOrderStatus::Pending->value, 0);
        $accepted = $counts->get(SmOrderStatus::Accepted->value, 0);
        $preparing = $counts->get(SmOrderStatus::Preparing->value, 0);
        $readyForPickup = $counts->get(SmOrderStatus::ReadyForPickup->value, 0);
        $pickedUp = $counts->get(SmOrderStatus::PickedUp->value, 0);
        $completed = $counts->get(SmOrderStatus::Completed->value, 0);
        $cancelled = $counts->get(SmOrderStatus::Cancelled->value, 0);

        return response()->json([
            'success' => true,
            'message' => 'Order counts retrieved successfully.',
            'data' => [
                'total' => $counts->sum(),
                'pending' => $pending,
                'accepted' => $accepted,
                'preparing' => $preparing,
                'ready_for_pickup' => $readyForPickup,
                'ready_for_delivery' => $readyForPickup,
                'picked_up' => $pickedUp,
                'completed' => $completed,
                'cancelled' => $cancelled,
            ],
        ]);
    }
}
