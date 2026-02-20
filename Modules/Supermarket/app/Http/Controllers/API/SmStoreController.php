<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmStoreData;
use Modules\Supermarket\Http\Requests\SmStoreRequest;
use Modules\Supermarket\Http\Requests\SmStoreRequests\SmStoreFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmStoreService;
use Throwable;

final class SmStoreController
{
    public function __construct(private SmStoreService $smStoreService) {}

    public function index(SmStoreFilterRequest $request): AnonymousResourceCollection
    {
        $stores = SmStore::getQuery()
            ->with('owner')
            ->paginate($request->perPage);

        return SmStoreResource::collection($stores);
    }

    /**
     * @throws Throwable
     */
    public function store(SmStoreRequest $request): SmStoreResource
    {
        $store = $this->smStoreService->store(SmStoreData::from($request->validated()));

        return SmStoreResource::make($store->load('owner'));
    }

    public function show(SmStore $smStore): SmStoreResource
    {
        return SmStoreResource::make($smStore->load([
            'owner',
            'storeHours',
            'documents',
            'trustLogs',
            'dailyStats',
        ]));
    }

    /**
     * @throws Throwable
     */
    public function update(SmStoreRequest $request, SmStore $smStore): SmStoreResource
    {
        $updatedStore = $this->smStoreService->update(SmStoreData::from($request->validated()), $smStore);

        return SmStoreResource::make($updatedStore->load('owner'));
    }

    public function destroy(SmStore $smStore): Response
    {
        $smStore->delete();

        return response()->noContent();
    }
}
