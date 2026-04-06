<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Resturants\Models\CuisineType;

final class UserRestaurantHomeCategoriesService
{
    /**
     * @return Collection<int, CuisineType>
     */
    public function categoriesForHome(): Collection
    {
        return CuisineType::query()
            ->whereHas('restaurants', fn ($q) => $q->where('is_active', true))
            ->with([
                'restaurants' => fn ($q) => $q
                    ->where('is_active', true)
                    ->with('media')
                    ->orderBy('restaurants.id'),
            ])
            ->orderBy('name')
            ->get();
    }
}
