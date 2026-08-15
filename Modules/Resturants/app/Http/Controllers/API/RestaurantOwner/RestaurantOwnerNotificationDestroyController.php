<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Models\SystemAlert;
use Illuminate\Http\Response;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerNotificationDestroyController
{
    public function __invoke(string $notification, RestaurantOwnerContext $context): Response
    {
        if (str_starts_with($notification, 'user:')) {
            $id = mb_substr($notification, 5);
            $context->owner()->notifications()->where('id', $id)->firstOrFail()->delete();

            return response()->noContent();
        }

        if (str_starts_with($notification, 'system:')) {
            $id = (int) mb_substr($notification, 7);

            SystemAlert::query()
                ->where('id', $id)
                ->where('booking_type', Order::class)
                ->whereIn(
                    'booking_id',
                    Order::query()
                        ->where('restaurant_id', $context->restaurant()->id)
                        ->select('id')
                )
                ->firstOrFail()
                ->delete();

            return response()->noContent();
        }

        abort(404);
    }
}
