<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use App\Models\Dispute;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Delivery\Http\Resources\DeliveryDisputeResource;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;

final class DriverDisputeController
{
    public function __invoke(\Illuminate\Http\Request $request): AnonymousResourceCollection
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        $disputes = Dispute::query()
            ->where('booking_type', 'delivery_order')
            ->whereHasMorph('booking', [DeliveryOrder::class], fn ($query) => $query->where('driver_id', $driver->id))
            ->latest('id')
            ->paginate((int) $request->integer('perPage', 10));

        return DeliveryDisputeResource::collection($disputes);
    }
}
