<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmOfferData;
use Modules\Supermarket\Http\Requests\SmOfferRequest;
use Modules\Supermarket\Http\Requests\SmOfferRequests\SmOfferFilterRequest;
use Modules\Supermarket\Http\Resources\SmOfferResource;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Services\SmOfferService;

final class SmOfferController
{
    public function __construct(
        private SmOfferService $service
    ) {}

    public function index(SmOfferFilterRequest $request): AnonymousResourceCollection
    {
        $offers = SmOffer::getQuery()
            ->withAnalyticsCounts()
            ->paginate($request->get('perPage', 20));

        return SmOfferResource::collection($offers);
    }

    public function store(SmOfferRequest $request): JsonResponse
    {
        $offer = $this->service->store(SmOfferData::from($request->validated()));

        return SmOfferResource::make($this->resolveOfferWithCounts($offer->id))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SmOffer $smOffer): SmOfferResource
    {
        return SmOfferResource::make($this->resolveOfferWithCounts($smOffer->id));
    }

    public function update(SmOfferRequest $request, SmOffer $smOffer): SmOfferResource
    {
        $offer = $this->service->update(SmOfferData::from($request->validated()), $smOffer);

        return SmOfferResource::make($this->resolveOfferWithCounts($offer->id));
    }

    public function destroy(SmOffer $smOffer): Response
    {
        $smOffer->delete();

        return response()->noContent();
    }

    private function resolveOfferWithCounts(int $offerId): SmOffer
    {
        return SmOffer::query()
            ->with('store')
            ->withAnalyticsCounts()
            ->findOrFail($offerId);
    }
}
