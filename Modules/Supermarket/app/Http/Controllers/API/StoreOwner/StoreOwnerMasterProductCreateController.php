<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Supermarket\Http\Requests\StoreOwnerMasterProductCreateRequest;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Services\SmProductService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerMasterProductCreateController
{
    public function __invoke(
        StoreOwnerMasterProductCreateRequest $request,
        StoreOwnerContextService $context,
        SmProductService $service
    ): JsonResponse {
        $validated = $request->validated();
        $owner = $context->owner();

        $createdProducts = $service->bulkCreateFromMasterProductIdsForOwner(
            masterProductIds: $validated['masterProductIds'],
            owner: $owner
        );

        return SmProductResource::collection($createdProducts)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
