<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Restaurant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Restaurant::class)
            ->allowedFilters([
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::exact('isFeatured', 'is_featured'),
                AllowedFilter::scope('isSuspended'),
                AllowedFilter::scope('cuisineType'),
                AllowedFilter::exact('priceRange', 'price_range'),
                AllowedFilter::scope('reputationScoreMin'),
                AllowedFilter::scope('reputationScoreMax'),
                AllowedFilter::scope('preparationTimeMin'),
                AllowedFilter::scope('preparationTimeMax'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('slug'),
                AllowedSort::field('reputationScore', 'reputation_score'),
                AllowedSort::field('preparationTimeMin', 'estimated_preparation_time_min'),
                AllowedSort::field('preparationTimeMax', 'estimated_preparation_time_max'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeIsSuspended(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereNotNull('suspension_until')->where('suspension_until', '>', now());
    }

    public function scopeCuisineType(Builder $query, mixed $value): Builder
    {
        if (empty($value)) {
            return $query;
        }

        return $query->whereHas('cuisineTypes', fn (Builder $q) => $q->where('cuisine_types.id', $value));
    }

    public function scopeReputationScoreMin(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('reputation_score', '>=', (int) $value);
    }

    public function scopeReputationScoreMax(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('reputation_score', '<=', (int) $value);
    }

    public function scopePreparationTimeMin(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->whereRaw(
            'COALESCE(estimated_preparation_time_min, CASE WHEN estimated_preparation_time - 10 < 1 THEN 1 ELSE estimated_preparation_time - 10 END) >= ?',
            [(int) $value],
        );
    }

    public function scopePreparationTimeMax(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->whereRaw(
            'COALESCE(estimated_preparation_time_max, estimated_preparation_time) <= ?',
            [(int) $value],
        );
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $term = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term);
        });
    }
}
