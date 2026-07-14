<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Services\DeepLinks\CanonicalDeepLinkGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Services\UserSupermarketCartService;

final class SmStoreShowController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
        private readonly CanonicalDeepLinkGenerator $deepLinkGenerator,
    ) {}

    public function __invoke(Request $request, int $store): JsonResponse
    {
        $now = CarbonImmutable::now();

        $model = SmStore::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('suspension_until')
                ->orWhere('suspension_until', '<=', $now))
            ->with([
                'owner',
                'highestDiscountOffer',
                'storeHours',
                'categories',
                'products' => fn ($query) => $query
                    ->where('is_available', true)
                    ->latest('id')
                    ->limit(5)
                    ->with([
                        'category',
                        'media',
                        'offerProducts.offer',
                    ]),
                'offers',
                'coupons',
                'orders',
                'documents',
                'trustLogs',
                'dailyStats',
                'commissionRules',
                'assistantQueries',
                'recurringOrders',
                //  'staff.user',
            ])
            ->findOrFail($store);

        $cartPayload = null;
        $user = $request->user('sanctum');
        if ($user !== null) {
            $isFavorited = Favorite::query()
                ->where('user_id', $user->id)
                ->where('favorable_type', SmStore::class)
                ->where('favorable_id', $model->id)
                ->exists();

            $model->setAttribute('isFavoritedByUser', $isFavorited);

            $products = $model->products;
            if ($products->isNotEmpty()) {
                $favoriteType = $products->first()?->getMorphClass();

                $favoritedProductIds = is_string($favoriteType) && $favoriteType !== ''
                    ? Favorite::query()
                        ->where('user_id', $user->id)
                        ->where('favorable_type', $favoriteType)
                        ->whereIn('favorable_id', $products->modelKeys())
                        ->pluck('favorable_id')
                        ->flip()
                    : collect();

                $products->each(function (SmProduct $product) use ($favoritedProductIds): void {
                    $product->setAttribute('isFavoritedByUser', $favoritedProductIds->has($product->id));
                });
            }

            $cartPayload = $this->carts->showForStore((int) $user->id, (int) $model->id);
            $model->setAttribute('cartPayload', $cartPayload);
        } else {
            $model->setAttribute('isFavoritedByUser', false);
            $model->setAttribute('cartPayload', null);
            $model->products->each(fn (SmProduct $product) => $product->setAttribute('isFavoritedByUser', false));
        }

        return response()->json([
            'store' => SmStoreResource::make($model),
            'cart' => $cartPayload,
            'shareUrl' => $this->deepLinkGenerator->store((int) $model->id),
        ]);
    }
}
