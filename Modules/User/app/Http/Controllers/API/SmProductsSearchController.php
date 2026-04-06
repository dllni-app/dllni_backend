<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\User\Http\Requests\DiscoverSupermarketProductsRequest;

final class SmProductsSearchController
{
    public function __invoke(DiscoverSupermarketProductsRequest $request): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        $query = SmProduct::getQuery()
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($q) => $q
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->with(['media', 'store']);

        $search = $request->validated('search');
        if (is_string($search) && $search !== '') {
            $query->search($search);
        }

        $products = $query->paginate($request->integer('perPage', 20));

        return SmProductResource::collection($products);
    }
}
