<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Http\Requests\OfferRequest;
use Modules\Resturants\Http\Requests\OfferRequests\OfferFilterRequest;
use Modules\Resturants\Http\Resources\OfferResource;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Services\OfferService;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Throwable;

final class RestaurantOwnerOffersController
{
    public function __construct(
        private OfferService $offerService,
        private ActivityLogService $activityLogService
    ) {}

    public function index(OfferFilterRequest $request, RestaurantOwnerContext $ownerContext): AnonymousResourceCollection
    {
        $restaurant = $ownerContext->restaurant();

        $offers = Offer::getQuery()
            ->with(['restaurant', 'products', 'rewardProducts'])
            ->where('restaurant_id', $restaurant->id)
            ->when(! RestaurantPromotionCapability::supported(), fn ($q) => $q->where('offer_type', 'product_discount'))
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

        return OfferResource::make($offer->load(['restaurant', 'products', 'rewardProducts']));
    }

    public function show(Offer $offer, RestaurantOwnerContext $ownerContext): OfferResource
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);
        abort_unless($offer->isProductDiscount() || RestaurantPromotionCapability::supported(), Response::HTTP_NOT_FOUND);

        $offer->load(['restaurant', 'products', 'rewardProducts']);

        return OfferResource::make($offer);
    }

    /** @throws Throwable */
    public function update(OfferRequest $request, Offer $offer, RestaurantOwnerContext $ownerContext): OfferResource
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);
        abort_unless($offer->isProductDiscount() || RestaurantPromotionCapability::supported(), Response::HTTP_NOT_FOUND);
        $request->merge(['restaurantId' => $restaurant->id]);

        $updated = $this->offerService->update(
            OfferData::from($request->validated()),
            $offer
        );

        return OfferResource::make($updated->load(['restaurant', 'products', 'rewardProducts']));
    }

    public function destroy(Offer $offer, RestaurantOwnerContext $ownerContext): Response
    {
        $restaurant = $ownerContext->restaurant();
        abort_unless($ownerContext->modelBelongsToRestaurant($offer, (int) $restaurant->id), Response::HTTP_NOT_FOUND);
        abort_unless($offer->isProductDiscount() || RestaurantPromotionCapability::supported(), Response::HTTP_NOT_FOUND);

        $offerName = $offer->name;
        $restaurantId = (int) $offer->restaurant_id;
        $offer->delete();
        $this->activityLogService->logOfferDeleted($offerName, $restaurantId);

        return response()->noContent();
    }
}