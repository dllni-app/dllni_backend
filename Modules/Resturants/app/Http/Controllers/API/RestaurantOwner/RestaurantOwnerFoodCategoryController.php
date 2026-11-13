<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\FoodDiscoveryCategory;

/**
 * Owner catalogue metadata. The route is protected by Sanctum + ro.menu.
 * Unlike customer discovery, this includes categories with no products yet.
 */
final class RestaurantOwnerFoodCategoryController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => FoodDiscoveryCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'slug', 'name_ar', 'name_en'])
                ->map(static fn (FoodDiscoveryCategory $category): array => [
                    'id' => (int) $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name_ar,
                ])
                ->all(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
