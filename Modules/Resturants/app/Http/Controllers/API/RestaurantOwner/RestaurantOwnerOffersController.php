<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Http\Requests\OfferRequest;
use Modules\Resturants\Http\Requests\OfferRequests\OfferFilterRequest;
use Modules\Resturants\Http\Resources\OfferResource;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Services\OfferService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Throwable;

final class RestaurantOwnerOffersController
{
    public function __construct(
        private OfferService $offerService
    ) {}

    public function index(OfferFilterRequest $request, RestaurantOwnerContext $ownerContext): AnonymousResourceCollection
    {
        $restaurant = $ownerContext->restaurant();

        $offers = Offer::getQuery()
            ->with(['restaurant', 'products'])
            ->where('restaurant_id', $restaurant->id)
            ->paginate($request->get('perPage', 10));

        return OfferResource::collection($offers);
    }

    /** @throws Throwable */
    public function store(OfferRequest $request, RestaurantOwnerContext $ownerContext): OfferResource
    {
        $restaurant = $ownerContext->restaurant();
        $request->merge(['restaurantId' => $restaurant->id]);

        $offer = $this->offerService->store(
            OfferData::from($request->validated())
        );

        return OfferResource::make($offer->load(['restaurant', 'products']));
    }

    public function show(Offer $offer, RestaurantOwnerContext $ownerContext): OfferResource
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);

        $offer->load(['restaurant', 'products']);

        return OfferResource::make($offer);
    }

    /** @throws Throwable */
    public function update(OfferRequest $request, Offer $offer, RestaurantOwnerContext $ownerContext): OfferResource
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);
        $request->merge(['restaurantId' => $restaurant->id]);

        $updated = $this->offerService->update(
            OfferData::from($request->validated()),
            $offer
        );

        return OfferResource::make($updated->load(['restaurant', 'products']));
    }

    public function destroy(Offer $offer, RestaurantOwnerContext $ownerContext): Response
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);

        $offer->delete();

        return response()->noContent();
    }
}
