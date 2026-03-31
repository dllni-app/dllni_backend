<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmStoreRequests\SmStoreFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;

final class SmStoresIndexController
{
    public function __invoke(SmStoreFilterRequest $request): AnonymousResourceCollection
    {
        $stores = SmStore::getQuery()
            ->where('is_active', true)
            ->orderByDesc('is_featured')
            ->with('owner')
            ->paginate($request->get('perPage', 20));

        return SmStoreResource::collection($stores);
    }
}
