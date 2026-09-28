<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\PromoCodeData;
use Modules\Resturants\Http\Requests\PromoCodeRequest;
use Modules\Resturants\Http\Requests\PromoCodeRequests\PromoCodeFilterRequest;
use Modules\Resturants\Http\Resources\PromoCodeResource;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Services\PromoCodeService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class PromoCodeController
{
    public function __construct(
        private PromoCodeService $promoCodeService,
        private RestaurantOwnerContext $ownerContext,
    ) {}

    public function index(PromoCodeFilterRequest $request, RestaurantOwnerContext $ownerContext): AnonymousResourceCollection
    {
        $promoCodes = PromoCode::getQuery()
            ->where('restaurant_id', $ownerContext->restaurantId())
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 10));

        return PromoCodeResource::collection($promoCodes);
    }

    /** @throws Throwable */
    public function store(PromoCodeRequest $request, RestaurantOwnerContext $ownerContext): PromoCodeResource
    {
        $restaurant = $ownerContext->restaurant();

        $promoCode = $this->promoCodeService->store(
            PromoCodeData::from([
                ...$request->validated(),
                'restaurantId' => $restaurant->id,
            ])
        );

        return PromoCodeResource::make($promoCode->load(['restaurant']));
    }

    public function show(PromoCode $promoCode, RestaurantOwnerContext $ownerContext): PromoCodeResource
    {
        abort_unless($ownerContext->modelBelongsToRestaurant($promoCode, $ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $promoCode->load(['restaurant']);

        return PromoCodeResource::make($promoCode);
    }

    /** @throws Throwable */
    public function update(PromoCodeRequest $request, PromoCode $promoCode, RestaurantOwnerContext $ownerContext): PromoCodeResource
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($promoCode, (int) $restaurant->id), Response::HTTP_NOT_FOUND);

        $updated = $this->promoCodeService->update(
            PromoCodeData::from([
                ...$request->validated(),
                'restaurantId' => $restaurant->id,
            ]),
            $promoCode
        );

        return PromoCodeResource::make($updated->load(['restaurant']));
    }

    public function destroy(PromoCode $promoCode, RestaurantOwnerContext $ownerContext): Response
    {
        abort_unless($ownerContext->modelBelongsToRestaurant($promoCode, $ownerContext->restaurantId()), Response::HTTP_NOT_FOUND);
        $promoCode->delete();

        return response()->noContent();
    }
}
