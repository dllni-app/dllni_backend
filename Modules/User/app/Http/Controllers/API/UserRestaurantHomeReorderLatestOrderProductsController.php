<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantHomeReorderLatestOrderProductsRequest;
use Modules\User\Services\UserRestaurantReorderLatestOrderProductsService;

final class UserRestaurantHomeReorderLatestOrderProductsController
{
    public function __construct(
        private UserRestaurantReorderLatestOrderProductsService $service,
    ) {}

    public function __invoke(RestaurantHomeReorderLatestOrderProductsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $result = $this->service->reorderLatestOrderProducts((int) $user->id);

        return response()->json([
            'message' => 'Latest order products added to cart.',
            'cartId' => $result['cartId'],
            'itemIds' => $result['itemIds'],
            'itemsAdded' => $result['itemsAdded'],
        ], 201);
    }
}
