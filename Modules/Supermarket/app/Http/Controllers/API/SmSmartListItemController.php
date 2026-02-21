<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmSmartListItemRequests\SmSmartListItemFilterRequest;
use Modules\Supermarket\Http\Resources\SmSmartListItemResource;
use Modules\Supermarket\Models\SmSmartListItem;

final class SmSmartListItemController
{
    public function index(SmSmartListItemFilterRequest $request): AnonymousResourceCollection
    {
        $items = SmSmartListItem::getQuery()->paginate($request->get('perPage', 20));

        return SmSmartListItemResource::collection($items);
    }

    public function show(SmSmartListItem $smSmartListItem): SmSmartListItemResource
    {
        return SmSmartListItemResource::make($smSmartListItem->load(['smartList', 'masterProduct']));
    }
}
