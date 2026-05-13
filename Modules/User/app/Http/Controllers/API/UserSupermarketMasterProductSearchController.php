<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\MasterProduct;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\MasterProductResource;
use Modules\User\Http\Requests\UserSupermarketMasterProductSearchRequest;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;

final class UserSupermarketMasterProductSearchController
{
    public function __invoke(UserSupermarketMasterProductSearchRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $index = mb_trim((string) $validated['index']);
        $perPage = (int) ($validated['perPage'] ?? 20);
        $escapedIndex = SearchTermEscaper::escape($index);

        $masterProducts = MasterProduct::query()
            ->with('media')
            ->where('is_active', true)
            ->where(function ($query) use ($escapedIndex): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", ["{$escapedIndex}%"]);
            })
            ->orderByRaw("CASE WHEN name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", ["{$escapedIndex}%"])
            ->orderBy('name')
            ->paginate($perPage);

        return MasterProductResource::collection($masterProducts);
    }
}
