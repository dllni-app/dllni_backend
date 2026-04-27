<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerDashboardPerformanceRequest;
use Modules\Resturants\Services\RestaurantOwnerDashboardService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerTopSellingProductsController
{
    public function __invoke(
        OwnerDashboardPerformanceRequest $request,
        RestaurantOwnerContext $context,
        RestaurantOwnerDashboardService $dashboardService
    ): JsonResponse {
        $restaurant = $context->restaurant();
        $supermarket = $context->supermarket();

        $performance = $dashboardService->performance(
            $restaurant,
            $request->string('range')->toString() ?: 'today',
            $request->input('from'),
            $request->input('to')
        );

        return response()->json([
            'range' => $performance['range'],
            'supermarket' => [
                'id' => (int) $supermarket->id,
                'name' => (string) $supermarket->name,
            ],
            'topProducts' => $performance['topProducts'],
            'offersImpact' => $performance['offersImpact'],
            'bestOfferPerformance' => $performance['bestOfferPerformance'],
        ]);
    }
}

