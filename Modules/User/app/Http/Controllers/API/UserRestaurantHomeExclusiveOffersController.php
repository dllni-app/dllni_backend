<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantHomeExclusiveOffersRequest;
use Modules\User\Http\Resources\UserRestaurantExclusiveOfferResource;
use Modules\User\Services\UserRestaurantExclusiveOffersService;

final class UserRestaurantHomeExclusiveOffersController
{
    public function __construct(
        private UserRestaurantExclusiveOffersService $exclusiveOffersService,
    ) {}

    public function __invoke(RestaurantHomeExclusiveOffersRequest $request): JsonResponse
    {
        $offers = $this->exclusiveOffersService->exclusiveOffersNearYou($request);

        return response()->json([
            'exclusiveOffers' => UserRestaurantExclusiveOfferResource::collection($offers),
        ]);
    }
}
