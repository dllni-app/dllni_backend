<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class InternalAiCatalogController
{
    public function restaurants(Request $request): AnonymousResourceCollection
    {
        $restaurants = Restaurant::query()
            ->with('cuisineTypes')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return RestaurantResource::collection($restaurants);
    }

    public function restaurantProducts(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with(['restaurant', 'category'])
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ProductResource::collection($products);
    }

    public function supermarketStores(Request $request): AnonymousResourceCollection
    {
        $stores = SmStore::query()
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return SmStoreResource::collection($stores);
    }

    public function supermarketProducts(Request $request): AnonymousResourceCollection
    {
        $products = SmProduct::query()
            ->with(['store', 'category', 'media', 'masterProduct.media'])
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return SmProductResource::collection($products);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(250, (int) $request->integer('perPage', 100)));
    }
}
