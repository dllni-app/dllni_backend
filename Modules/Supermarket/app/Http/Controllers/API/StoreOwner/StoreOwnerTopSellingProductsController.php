<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\StoreOwnerDashboardPerformanceRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Modules\Supermarket\Services\StoreOwnerDashboardPerformanceService;

final class StoreOwnerTopSellingProductsController
{
    public function __invoke(
        StoreOwnerDashboardPerformanceRequest $request,
        StoreOwnerContextService $context,
        StoreOwnerDashboardPerformanceService $dashboardService
    ): JsonResponse {
        $store = $context->ownedStore();

        $performance = $dashboardService->performance(
            $store,
            $request->string('range')->toString() ?: 'today',
            $request->input('from'),
            $request->input('to')
        );

        return response()->json([
            'range' => $performance['range'],
            'supermarket' => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
            ],
            'topProducts' => $performance['topProducts'],
            'offersImpact' => $performance['offersImpact'],
            'bestOfferPerformance' => $performance['bestOfferPerformance'],
        ]);
    }
}
