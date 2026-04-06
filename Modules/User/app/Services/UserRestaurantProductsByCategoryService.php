<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;

final class UserRestaurantProductsByCategoryService
{
    public function paginateProductsByCategory(
        int $categoryId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        // Verify category exists.
        $category = Category::findOrFail($categoryId);

        $query = Product::query()
            ->where('category_id', $categoryId)
            ->where('is_available', true)
            ->whereHas('restaurant', fn ($query) => $query->where('is_active', true))
            ->with([
                'offers' => function ($query) {
                    $query->where('is_active', true)
                        ->where(function ($q) {
                            $q->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                        });
                },
                'restaurant' => fn ($q) => $q->select(['id', 'name', 'city', 'district']),
                'category' => fn ($q) => $q->select(['id', 'name']),
                'media',
            ]);

        return $query->paginate($perPage);
    }
}
