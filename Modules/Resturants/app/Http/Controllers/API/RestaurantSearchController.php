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

        if (! $request->has('filter.isAvailable')) {
            $productQuery->where('is_available', true);
        }

        $productQuery->whereHas('restaurant', static function ($query): void {
            $query->where('is_active', true);
        });

        if (! $request->filled('sort')) {
            $search = (string) data_get($request->validated(), 'filter.search', '');

            if ($search !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $namePrefix = $escaped.'%';
                $nameContains = '%'.$escaped.'%';
                $slugContains = '%'.$escaped.'%';

                $productQuery->reorder()
                    ->orderByRaw(
                        'CASE '.
                        'WHEN name LIKE ? THEN 0 '.
                        'WHEN name LIKE ? THEN 1 '.
                        'WHEN slug LIKE ? THEN 2 '.
                        'ELSE 3 END',
                        [$namePrefix, $nameContains, $slugContains]
                    )
                    ->orderByDesc('is_featured')
                    ->orderByDesc('created_at');
            }
        }

        return RestaurantSearchProductResource::collection(
            $productQuery->paginate($request->get('perPage', 20))
        );
    }
}
