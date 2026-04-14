<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Models\MasterProduct;
use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\StoreOwnerMasterProductSearchRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;

final class StoreOwnerMasterProductSearchController
{
    public function __invoke(StoreOwnerMasterProductSearchRequest $request, StoreOwnerContextService $context): JsonResponse
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
                    ->orWhereRaw("barcode LIKE ? ESCAPE '!'", ["{$escapedIndex}%"]);
            })
            ->orderByRaw("CASE WHEN name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", ["{$escapedIndex}%"])
            ->orderBy('name')
            ->paginate($perPage)
            ->through(static fn (MasterProduct $masterProduct): array => [
                'id' => $masterProduct->id,
                'masterProductId' => $masterProduct->id,
                'name' => $masterProduct->name,
                'barcode' => $masterProduct->barcode,
            ]);

        return response()->json($masterProducts);
    }
}
