<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Http\Resources\ActivityLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Spatie\Activitylog\Models\Activity;

final class RestaurantOwnerActivityLogController
{
    public function __invoke(Request $request, RestaurantOwnerContext $context): AnonymousResourceCollection
    {
        $request->validate([
            'logName' => 'nullable|string|in:products,offers,orders,system',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $restaurantId = $context->restaurantId();
        $perPage = (int) $request->get('perPage', 15);
        $logName = $request->get('logName');

        $query = Activity::query()
            ->whereJsonContains('properties->restaurant_id', $restaurantId)
            ->with('causer');

        if ($logName !== null) {
            $query->where('log_name', $logName);
        }

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return ActivityLogResource::collection($logs);
    }
}
