<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmOfferProductRequests\SmOfferProductFilterRequest;
use Modules\Supermarket\Http\Resources\SmOfferProductResource;
use Modules\Supermarket\Models\SmOfferProduct;

final class SmOfferProductController
{
    public function index(SmOfferProductFilterRequest $request): AnonymousResourceCollection
    {
        $items = SmOfferProduct::getQuery()->paginate($request->get('perPage', 20));

        return SmOfferProductResource::collection($items);
    }

    public function show(SmOfferProduct $smOfferProduct): SmOfferProductResource
    {
        return SmOfferProductResource::make($smOfferProduct->load(['offer', 'product']));
    }
}
