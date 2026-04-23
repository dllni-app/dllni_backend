<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Modules\Supermarket\Data\SmStoreData;
use Modules\Supermarket\Http\Requests\SmStoreRequest;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Services\SmStoreService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerStoreController
{
    public function __construct(
        private SmStoreService $smStoreService,
        private StoreOwnerContextService $context
    ) {}

    public function show(): SmStoreResource
    {
        $store = $this->context->ownedStore();

        return SmStoreResource::make($store->load('owner'));
    }

    public function update(SmStoreRequest $request): SmStoreResource
    {
        $store = $this->context->ownedStore();

        $updatedStore = $this->smStoreService->update(
            SmStoreData::from($request->validated()),
            $store
        );

        return SmStoreResource::make($updatedStore->load('owner'));
    }
}
