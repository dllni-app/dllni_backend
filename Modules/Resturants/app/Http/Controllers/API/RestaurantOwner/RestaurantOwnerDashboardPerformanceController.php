<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerDashboardPerformanceRequest;
use Modules\Resturants\Services\RestaurantOwnerDashboardService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerDashboardPerformanceController
{
    public function __invoke(
        OwnerDashboardPerformanceRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerDashboardService $dashboardService
    ): JsonResponse {
        $restaurant = $context->restaurant();

        return response()->json(
            $dashboardService->performance(
                $restaurant,
                $request->string('range')->toString() ?: 'today',
                $request->input('from'),
                $request->input('to')
            )
        );
    }
}
