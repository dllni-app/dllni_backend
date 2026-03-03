<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Http\Requests\OfferRequest;
use Modules\Resturants\Http\Requests\OfferRequests\OfferFilterRequest;
use Modules\Resturants\Http\Resources\OfferResource;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Services\OfferService;
use Throwable;

final class OfferController
{
    public function __construct(
        private OfferService $offerService
    ) {}

    public function index(OfferFilterRequest $request): AnonymousResourceCollection
    {
        $offers = Offer::getQuery()
            ->with(['restaurant', 'products'])
            ->paginate($request->get('perPage', 10));

        return OfferResource::collection($offers);
    }

    /** @throws Throwable */
    public function store(OfferRequest $request): OfferResource
    {
        $offer = $this->offerService->store(
            OfferData::from($request->validated())
        );

        return OfferResource::make($offer->load(['restaurant', 'products']));
    }

    public function show(Offer $offer): OfferResource
    {
        $offer->load(['restaurant', 'products']);

        return OfferResource::make($offer);
    }

    /** @throws Throwable */
    public function update(OfferRequest $request, Offer $offer): OfferResource
    {
        $updated = $this->offerService->update(
            OfferData::from($request->validated()),
            $offer
        );

        return OfferResource::make($updated->load(['restaurant', 'products']));
    }

    public function destroy(Offer $offer): Response
    {
        $offer->delete();

        return response()->noContent();
    }
}
