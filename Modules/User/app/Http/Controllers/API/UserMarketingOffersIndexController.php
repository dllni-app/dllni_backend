<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserMarketingOfferResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\User\Http\Requests\UserMarketingOffersIndexRequest;
use Modules\User\Services\UserMarketingOfferService;

final class UserMarketingOffersIndexController
{
    public function __invoke(UserMarketingOffersIndexRequest $request, UserMarketingOfferService $offers): AnonymousResourceCollection
    {
        $perPage = (int) $request->validated('perPage', 15);
        $paginator = $offers->paginateCurrentlyValid($perPage);

        return UserMarketingOfferResource::collection($paginator);
    }
}
