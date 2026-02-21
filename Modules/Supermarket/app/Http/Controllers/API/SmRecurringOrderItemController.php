<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmRecurringOrderItemRequests\SmRecurringOrderItemFilterRequest;
use Modules\Supermarket\Http\Resources\SmRecurringOrderItemResource;
use Modules\Supermarket\Models\SmRecurringOrderItem;

final class SmRecurringOrderItemController
{
    public function index(SmRecurringOrderItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = SmRecurringOrderItem::getQuery()->paginate($request->get('perPage', 20));

        return SmRecurringOrderItemResource::collection($items);
    }

    public function show(SmRecurringOrderItem $smRecurringOrderItem): SmRecurringOrderItemResource
    {
        return SmRecurringOrderItemResource::make($smRecurringOrderItem->load(['recurringOrder', 'masterProduct']));
    }
}
