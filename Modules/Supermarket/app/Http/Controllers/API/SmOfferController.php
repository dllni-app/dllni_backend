<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Modules\Supermarket\Data\SmOfferData;
use Modules\Supermarket\Http\Requests\SmOfferRequest;
use Modules\Supermarket\Http\Requests\SmOfferRequests\SmOfferFilterRequest;
use Modules\Supermarket\Http\Resources\SmOfferResource;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Services\SmOfferService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmOfferController
{
    public function __construct(
        private SmOfferService $service,
        private ActivityLogService $activityLogService,
        private StoreOwnerContextService $context,
    ) {}

    public function index(SmOfferFilterRequest $request): AnonymousResourceCollection
    {
        $offers = SmOffer::getQuery()
            ->where('store_id', $this->context->ownedStore()->id)
            ->withAnalyticsCounts()
            ->paginate($request->get('perPage', 20));

        return SmOfferResource::collection($offers);
    }

    public function store(SmOfferRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $offerProducts = $validated['offerProducts'] ?? null;
        $offer = $this->service->store(
            SmOfferData::from(Arr::except($validated, ['offerProducts'])),
            $offerProducts,
        );

        return SmOfferResource::make($this->resolveOfferWithCounts($offer->id))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SmOffer $smOffer): SmOfferResource
    {
        $this->context->store((int) $smOffer->store_id);

        return SmOfferResource::make($this->resolveOfferWithCounts($smOffer->id));
    }

    public function update(SmOfferRequest $request, SmOffer $smOffer): SmOfferResource
    {
        $this->context->store((int) $smOffer->store_id);
        $validated = $request->validated();
        $offerProducts = $validated['offerProducts'] ?? null;
        $offer = $this->service->update(
            SmOfferData::from(Arr::except($validated, ['offerProducts'])),
            $smOffer,
            $offerProducts,
        );

        return SmOfferResource::make($this->resolveOfferWithCounts($offer->id));
    }

    public function destroy(SmOffer $smOffer): Response
    {
        $this->context->store((int) $smOffer->store_id);
        $offerName = $smOffer->name;
        $storeId = (int) $smOffer->store_id;
        $smOffer->delete();
        $this->activityLogService->logSmOfferDeleted($offerName, $storeId);

        return response()->noContent();
    }

    private function resolveOfferWithCounts(int $offerId): SmOffer
    {
        return SmOffer::query()
            ->where('store_id', $this->context->ownedStore()->id)
            ->with('store')
            ->withAnalyticsCounts()
            ->findOrFail($offerId);
    }
}
