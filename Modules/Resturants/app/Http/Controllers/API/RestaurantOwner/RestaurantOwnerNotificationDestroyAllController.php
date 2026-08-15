<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Models\SystemAlert;
use Illuminate\Http\Response;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerNotificationDestroyAllController
{
    public function __invoke(RestaurantOwnerContext $context): Response
    {
        $context->owner()->notifications()->delete();

        SystemAlert::query()
            ->where('booking_type', Order::class)
            ->whereIn(
                'booking_id',
                Order::query()
                    ->where('restaurant_id', $context->restaurant()->id)
                    ->select('id')
            )
            ->delete();

        return response()->noContent();
    }
}
