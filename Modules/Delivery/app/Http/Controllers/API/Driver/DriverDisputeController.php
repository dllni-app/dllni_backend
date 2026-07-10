<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Delivery\Http\Resources\DeliveryDisputeResource;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;

final class DriverDisputeController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        $disputes = Dispute::query()
            ->with(['booking', 'trustLogs'])
            ->where('booking_type', 'delivery_order')
            ->whereHasMorph('booking', [DeliveryOrder::class], fn ($query) => $query->where('driver_id', $driver->id))
            ->latest('id')
            ->paginate((int) $request->integer('perPage', 10));

        $openCount = (int) $driver->open_disputes_count;
        $totalCount = (int) $disputes->total();

        return DeliveryDisputeResource::collection($disputes)->additional([
            'summary' => [
                'openCount' => $openCount,
                'resolvedCount' => max($totalCount - $openCount, 0),
                'totalCount' => $totalCount,
            ],
        ]);
    }
}
