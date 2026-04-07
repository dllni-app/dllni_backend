<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;

final class SmProductShowController
{
    public function __invoke(Request $request, int $product): JsonResponse
    {
        $now = CarbonImmutable::now();

        $model = SmProduct::query()
            ->where('is_available', true)
            ->whereHas('store', fn($query) => $query
                ->where('is_active', true)
                ->where(fn($storeQuery) => $storeQuery
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->with(['store', 'category', 'media', 'offerProducts.offer'])
            ->findOrFail($product);

        $user = $request->user('sanctum');
        if ($user !== null) {
            $isFavorited = Favorite::query()
                ->where('user_id', $user->id)
                ->where('favorable_type', $model->getMorphClass())
                ->where('favorable_id', $model->id)
                ->exists();

            $model->setAttribute('isFavoritedByUser', $isFavorited);
        } else {
            $model->setAttribute('isFavoritedByUser', false);
        }

        $resource = SmProductResource::make($model);

        return response()->json([
            'data' => $resource,
            'product' => $resource,
        ]);
    }
}
