<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;

final class SmProductSimilarSearchController
{
    public function __invoke(int $product): AnonymousResourceCollection
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

        $searchTerm = SearchTermEscaper::escape($selectedProduct->name ?? '');

        $products = SmProduct::query()
            ->whereKeyNot($selectedProduct->id)
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->whereRaw("name LIKE ? ESCAPE '!'", ["%{$searchTerm}%"])
            ->with(['store', 'category', 'media', 'offerProducts.offer'])
            ->paginate(20);

        return SmProductResource::collection($products);
    }
}
