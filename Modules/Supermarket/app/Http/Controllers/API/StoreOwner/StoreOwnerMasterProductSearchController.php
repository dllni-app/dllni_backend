<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Models\MasterProduct;
use Modules\Supermarket\Http\Requests\StoreOwnerMasterProductSearchRequest;
use Modules\Supermarket\Http\Resources\MasterProductResource;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;

final class StoreOwnerMasterProductSearchController
{
    public function __invoke(StoreOwnerMasterProductSearchRequest $request, StoreOwnerContextService $context)
    {
        $context->owner();

        $validated = $request->validated();
        $index = mb_trim((string) $validated['index']);
        $perPage = (int) ($validated['perPage'] ?? 20);
        $escapedIndex = SearchTermEscaper::escape($index);

        $masterProducts = MasterProduct::query()
            ->where('is_active', true)
            ->where(function ($query) use ($escapedIndex): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", ["{$escapedIndex}%"])
                    ->orWhereRaw("barcode LIKE ? ESCAPE '!'", ["{$escapedIndex}%"])
                    ->orWhereHas('aliases', function ($aliasQuery) use ($escapedIndex): void {
                        $aliasQuery->whereRaw("alias LIKE ? ESCAPE '!'", ["{$escapedIndex}%"]);
                    });
            })
            ->orderByRaw(
                "CASE
                    WHEN name LIKE ? ESCAPE '!' THEN 0
                    WHEN barcode LIKE ? ESCAPE '!' THEN 1
                    WHEN EXISTS (
                        SELECT 1
                        FROM master_product_aliases
                        WHERE master_product_aliases.master_product_id = master_products.id
                          AND master_product_aliases.alias LIKE ? ESCAPE '!'
                    ) THEN 2
                    ELSE 3
                 END",
                ["{$escapedIndex}%", "{$escapedIndex}%", "{$escapedIndex}%"]
            )
            ->orderBy('name')
            ->paginate($perPage);

        return MasterProductResource::collection($masterProducts->load('media'))
            ->response()
            ->setStatusCode(200);
    }
}
