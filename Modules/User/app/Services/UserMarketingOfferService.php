<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\User\Models\MarketingOffer;

final class UserMarketingOfferService
{
    public function paginateCurrentlyValid(int $perPage): LengthAwarePaginator
    {
        return MarketingOffer::query()
            ->currentlyValid()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
