<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Modules\Supermarket\Data\SmStoreData;
use Modules\Supermarket\Http\Requests\SmStoreRequest;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmStoreService;

final class StoreOwnerStoreController
{
    public function __construct(private SmStoreService $smStoreService) {}

    public function show(SmStore $store): SmStoreResource
    {
        return SmStoreResource::make($store->load('owner'));
    }

    public function update(SmStoreRequest $request, SmStore $store): SmStoreResource
    {
        $updatedStore = $this->smStoreService->update(
            SmStoreData::from($request->validated()),
            $store
        );

        return SmStoreResource::make($updatedStore->load('owner'));
    }
}
