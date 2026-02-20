<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\PromoCodeData;
use Modules\Resturants\Http\Requests\PromoCodeRequest;
use Modules\Resturants\Http\Requests\PromoCodeRequests\PromoCodeFilterRequest;
use Modules\Resturants\Http\Resources\PromoCodeResource;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Services\PromoCodeService;
use Throwable;

final class PromoCodeController
{
    public function __construct(
        private PromoCodeService $promoCodeService
    ) {}

    public function index(PromoCodeFilterRequest $request): AnonymousResourceCollection
    {
        $promoCodes = PromoCode::getQuery()
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 20));

        return PromoCodeResource::collection($promoCodes);
    }

    /** @throws Throwable */
    public function store(PromoCodeRequest $request): PromoCodeResource
    {
        $promoCode = $this->promoCodeService->store(
            PromoCodeData::from($request->validated())
        );

        return PromoCodeResource::make($promoCode->load(['restaurant']));
    }

    public function show(PromoCode $promoCode): PromoCodeResource
    {
        $promoCode->load(['restaurant']);

        return PromoCodeResource::make($promoCode);
    }

    /** @throws Throwable */
    public function update(PromoCodeRequest $request, PromoCode $promoCode): PromoCodeResource
    {
        $updated = $this->promoCodeService->update(
            PromoCodeData::from($request->validated()),
            $promoCode
        );

        return PromoCodeResource::make($updated->load(['restaurant']));
    }

    public function destroy(PromoCode $promoCode): Response
    {
        $promoCode->delete();

        return response()->noContent();
    }
}
