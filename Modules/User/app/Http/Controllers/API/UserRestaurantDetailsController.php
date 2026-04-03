<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Modules\Resturants\Http\Resources\CategoryResource;
use Modules\Resturants\Http\Resources\OfferResource;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Http\Resources\ReviewResource;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
use Modules\User\Http\Requests\RestaurantDetailsRequest;

final class UserRestaurantDetailsController
{
    public function __invoke(RestaurantDetailsRequest $request, int $restaurant): JsonResponse
    {
        $model = Restaurant::query()->findOrFail($restaurant);

        $model->load([
            'media',
            'user',
            'operatingHours',
            'cuisineTypes',
        ]);

        $offers = Offer::query()
            ->where('restaurant_id', $model->id)
            ->where('is_active', true)
            ->with(['restaurant.media'])
            ->latest('starts_at')
            ->limit(10)
            ->get();

        $popularProducts = Product::query()
            ->where('restaurant_id', $model->id)
            ->where('is_available', true)
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->with(['media', 'category'])
            ->limit(10)
            ->get();

        $categories = $model->categories()
            ->orderBy('sort_order')
            ->with(['products' => fn ($q) => $q
                ->where('is_available', true)
                ->with('media')
                ->orderByDesc('is_featured')
                ->orderBy('name')])
            ->get();

        $reviewsQuery = Review::query()
            ->where('restaurant_id', $model->id)
            ->with('user')
            ->latest();

        $reviews = $reviewsQuery->paginate($request->integer('reviewsPerPage', 10));

        /** @var Collection<int, array{rating:int, aggregate:int}> $ratingCounts */
        $ratingCounts = Review::query()
            ->where('restaurant_id', $model->id)
            ->selectRaw('rating, count(*) as aggregate')
            ->groupBy('rating')
            ->get()
            ->map(fn ($row) => ['rating' => (int) $row->rating, 'aggregate' => (int) $row->aggregate]);

        $ratingsByValue = $ratingCounts->keyBy('rating')->map(fn ($row) => $row['aggregate']);
        $totalReviews = (int) $ratingCounts->sum('aggregate');

        $averageRating = (float) (Review::query()
            ->where('restaurant_id', $model->id)
            ->avg('rating') ?? 0);

        return response()->json([
            'restaurant' => RestaurantResource::make($model),
            'offers' => OfferResource::collection($offers),
            'popularProducts' => ProductResource::collection($popularProducts),
            'categories' => CategoryResource::collection($categories),
            'ratingSummary' => [
                'average' => round($averageRating, 1),
                'total' => $totalReviews,
                'counts' => [
                    '5' => (int) ($ratingsByValue[5] ?? 0),
                    '4' => (int) ($ratingsByValue[4] ?? 0),
                    '3' => (int) ($ratingsByValue[3] ?? 0),
                    '2' => (int) ($ratingsByValue[2] ?? 0),
                    '1' => (int) ($ratingsByValue[1] ?? 0),
                ],
            ],
            'reviews' => ReviewResource::collection($reviews),
        ]);
    }
}
