<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserMarketingOfferResource;
use Modules\User\Models\MarketingOffer;

final class UserMarketingOfferShowController
{
    public function __invoke(MarketingOffer $marketingOffer): UserMarketingOfferResource
    {
        return UserMarketingOfferResource::make($marketingOffer);
    }
}
