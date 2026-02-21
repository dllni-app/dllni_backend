<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmOrderItemRequests\SmOrderItemFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderItemResource;
use Modules\Supermarket\Models\SmOrderItem;

final class SmOrderItemController
{
    public function index(SmOrderItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = SmOrderItem::getQuery()->paginate($request->get('perPage', 20));

        return SmOrderItemResource::collection($items);
    }

    public function show(SmOrderItem $smOrderItem): SmOrderItemResource
    {
        return SmOrderItemResource::make($smOrderItem->load(['order', 'product']));
    }
}
