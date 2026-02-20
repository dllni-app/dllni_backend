<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\RestaurantOrderDisputeData;
use Modules\Resturants\Http\Requests\RestaurantOrderDisputeRequest;
use Modules\Resturants\Http\Requests\RestaurantOrderDisputeRequests\RestaurantOrderDisputeFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantOrderDisputeResource;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Modules\Resturants\Services\RestaurantOrderDisputeService;
use Throwable;

final class RestaurantOrderDisputeController
{
    public function __construct(
        private RestaurantOrderDisputeService $disputeService
    ) {}

    public function index(RestaurantOrderDisputeFilterRequest $request): AnonymousResourceCollection
    {
        $disputes = RestaurantOrderDispute::getQuery()
            ->with(['order', 'messages'])
            ->paginate($request->get('perPage', 20));

        return RestaurantOrderDisputeResource::collection($disputes);
    }

    /** @throws Throwable */
    public function store(RestaurantOrderDisputeRequest $request): RestaurantOrderDisputeResource
    {
        $dispute = $this->disputeService->store(
            RestaurantOrderDisputeData::from($request->validated())
        );

        return RestaurantOrderDisputeResource::make($dispute->load(['order', 'messages']));
    }

    public function show(RestaurantOrderDispute $restaurant_order_dispute): RestaurantOrderDisputeResource
    {
        $restaurant_order_dispute->load(['order', 'messages']);

        return RestaurantOrderDisputeResource::make($restaurant_order_dispute);
    }

    /** @throws Throwable */
    public function update(RestaurantOrderDisputeRequest $request, RestaurantOrderDispute $restaurant_order_dispute): RestaurantOrderDisputeResource
    {
        $updated = $this->disputeService->update(
            RestaurantOrderDisputeData::from($request->validated()),
            $restaurant_order_dispute
        );

        return RestaurantOrderDisputeResource::make($updated->load(['order', 'messages']));
    }

    public function destroy(RestaurantOrderDispute $restaurant_order_dispute): Response
    {
        $restaurant_order_dispute->delete();

        return response()->noContent();
    }
}
