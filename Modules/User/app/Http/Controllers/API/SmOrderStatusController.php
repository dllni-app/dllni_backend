<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderStatusController
{
    public function __invoke(int $order): JsonResponse
    {
        $userId = auth()->id();

        $smOrder = SmOrder::query()
            ->where('id', $order)
            ->where('customer_id', $userId)
            ->with([
                'store',
                'items.product.media',
                'statusLogs',
            ])
            ->firstOrFail();

        return response()->json([
            'order' => SmOrderResource::make($smOrder),
        ]);
    }
}
