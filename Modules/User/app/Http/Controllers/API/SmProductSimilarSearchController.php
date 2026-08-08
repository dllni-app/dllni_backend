<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;

final class SmProductSimilarSearchController
{
    public function __invoke(Request $request, int $product): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        $selectedProduct = SmProduct::query()
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->findOrFail($product);

        $isCompareRequest = str_ends_with(
            (string) ($request->route()?->uri() ?? ''),
            '/compare'
        );

        $perPage = max(
            1,
            min(100, $request->integer('per_page', $request->integer('perPage', 20)))
        );

        $productsQuery = SmProduct::query()
            ->whereKeyNot($selectedProduct->id)
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)));

        if ($isCompareRequest && $selectedProduct->master_product_id !== null) {
            $productsQuery->where('master_product_id', $selectedProduct->master_product_id);
        } else {
            $searchTerm = SearchTermEscaper::escape($selectedProduct->name ?? '');
            $productsQuery->whereRaw("name LIKE ? ESCAPE '!'", ["%{$searchTerm}%"]);
        }

        $products = $productsQuery
            ->with(['store', 'category', 'media', 'offerProducts.offer', 'masterProduct.media'])
            ->orderByRaw('COALESCE(discounted_price, price) ASC')
            ->orderBy('id')
            ->paginate($perPage);

        $user = $request->user('sanctum');
        if ($user !== null) {
            $favoriteIds = Favorite::query()
                ->where('user_id', $user->id)
                ->where('favorable_type', (new SmProduct())->getMorphClass())
                ->whereIn('favorable_id', $products->getCollection()->modelKeys())
                ->pluck('favorable_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $favoriteLookup = array_flip($favoriteIds);

            $products->getCollection()->each(function (SmProduct $item) use ($favoriteLookup): void {
                $item->setAttribute('isFavoritedByUser', isset($favoriteLookup[$item->id]));
            });
        } else {
            $products->getCollection()->each(function (SmProduct $item): void {
                $item->setAttribute('isFavoritedByUser', false);
            });
        }

        return SmProductResource::collection($products);
    }
}
