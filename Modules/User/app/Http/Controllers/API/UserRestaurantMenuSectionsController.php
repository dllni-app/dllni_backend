<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Http\Requests\UserRestaurantMenuSectionsRequest;

final class UserRestaurantMenuSectionsController
{
    public function __invoke(UserRestaurantMenuSectionsRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = Restaurant::query()
            ->where('is_active', true)
            ->findOrFail($restaurant);

        $itemsPerSection = $request->getItemsPerSection();

        $categories = $restaurantModel->categories()
            ->orderBy('sort_order')
            ->with([
                'products' => fn($query) => $query
                    ->where('is_available', true)
                    ->with('media')
                    ->orderByDesc('is_featured')
                    ->orderBy('name'),
            ])
            ->get();

        $user = $request->user('sanctum');
        $favoriteProductLookup = [];

        if ($user !== null) {
            $restaurantProductIds = $categories
                ->flatMap(fn($category) => $category->products->pluck('id'))
                ->map(fn($id): int => (int) $id)
                ->unique()
                ->values();

            if ($restaurantProductIds->isNotEmpty()) {
                $favoriteProductIds = $user->favorites()
                    ->where('favorable_type', 'Modules\\Resturants\\Models\\Product')
                    ->whereIn('favorable_id', $restaurantProductIds)
                    ->pluck('favorable_id')
                    ->map(fn($id): int => (int) $id)
                    ->all();

                $favoriteProductLookup = array_fill_keys($favoriteProductIds, true);
            }
        }

        $sections = $categories
            ->map(function ($category) use ($itemsPerSection, $favoriteProductLookup): array {
                $products = $category->products->take($itemsPerSection)->values();

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sortOrder' => $category->sort_order,
                    'totalProducts' => $category->products->count(),
                    'items' => $products->map(function ($product): array {
                        $price = $product->price !== null ? (float) $product->price : null;
                        $discountedPrice = $product->discounted_price !== null ? (float) $product->discounted_price : null;
                        $displayPrice = $discountedPrice !== null && $price !== null && $discountedPrice < $price
                            ? $discountedPrice
                            : $price;

                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'description' => $product->description,
                            'sizeLabel' => null,
                            'displayPrice' => $displayPrice,
                            'originalPrice' => $discountedPrice !== null && $price !== null && $discountedPrice < $price ? $price : null,
                            'currency' => config('app.currency', 'IQD'),
                            'primaryImageUrl' => $product->getFirstMediaUrl('primary-image') ?: null,
                            'isFeatured' => (bool) $product->is_featured,
                            'isFavorite' => isset($favoriteProductLookup[(int) $product->id]),
                        ];
                    })->values()->all(),
                ];
            })
            ->filter(fn(array $section): bool => $section['totalProducts'] > 0)
            ->values()
            ->all();

        return response()->json([
            'restaurantId' => $restaurantModel->id,
            'itemsPerSection' => $itemsPerSection,
            'sections' => $sections,
        ]);
    }
}
