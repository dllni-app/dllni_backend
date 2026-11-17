<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Models\SmOffer;
use Modules\User\Http\Resources\UserSupermarketOfferDetailResource;

final class UserSupermarketOfferShowController
{
    public function __invoke(int $offer): JsonResponse
    {
        $now = CarbonImmutable::now();

        $model = SmOffer::query()
            ->where('id', $offer)
            ->where('is_active', true)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(static function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->whereHas('store', static function ($query) use ($now): void {
                $query
                    ->where('is_active', true)
                    ->where('is_temporarily_closed', false)
                    ->where(static function ($active): void {
                        $active->whereNull('suspension_until')->orWhere('suspension_until', '<=', now());
                    });
            })
            ->with([
                'store',
                'offerProducts.product.media',
                'offerProducts.product.masterProduct.media',
            ])
            ->firstOrFail();

        return response()->json([
            'data' => UserSupermarketOfferDetailResource::make($model),
        ]);
    }
}
