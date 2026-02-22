<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Resturants\Http\Requests\RestaurantSearchRequest;

final class RestaurantSearchService
{
    public function search(RestaurantSearchRequest $request): LengthAwarePaginator {}
}
