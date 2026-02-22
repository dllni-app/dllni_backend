<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantSearchRequest;
use Modules\Resturants\Http\Resources\RestaurantSearchProductResource;
use Modules\Resturants\Models\Product;

final class RestaurantSearchController
{
    public function __invoke(RestaurantSearchRequest $request): AnonymousResourceCollection
    {
        $productQuery = Product::getQuery()
            ->with(['restaurant', 'category']);

        return RestaurantSearchProductResource::collection(
            $productQuery->paginate($request->get('perPage', 20))
        );
    }
}
