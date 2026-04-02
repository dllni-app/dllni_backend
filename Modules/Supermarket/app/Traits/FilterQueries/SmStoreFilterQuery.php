<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmStore;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmStoreFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmStore::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('slug'),
                AllowedFilter::partial('city'),
                AllowedFilter::partial('neighborhood'),
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::exact('isFeatured', 'is_featured'),
                AllowedFilter::scope('suspended'),
                AllowedFilter::scope('trustScoreMin'),
                AllowedFilter::scope('trustScoreMax'),
                AllowedFilter::scope('openNow'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('slug'),
                AllowedSort::field('city'),
                AllowedSort::field('neighborhood'),
                AllowedSort::field('averageRating', 'average_rating'),
                AllowedSort::field('totalReviews', 'total_reviews'),
                AllowedSort::field('trustScore', 'trust_score'),
                AllowedSort::field('warningCount', 'warning_count'),
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('updatedAt', 'updated_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSuspended(Builder $query, mixed $suspended): Builder
    {
        if ($suspended === null) {
            return $query;
        }

        $isSuspended = filter_var($suspended, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isSuspended === null) {
            return $query;
        }

        if ($isSuspended) {
            return $query->whereNotNull('suspension_until')
                ->where('suspension_until', '>', now());
        }

        return $query->where(function (Builder $builder): void {
            $builder->whereNull('suspension_until')
                ->orWhere('suspension_until', '<=', now());
        });
    }

    public function scopeTrustScoreMin(Builder $query, mixed $minScore): Builder
    {
        if ($minScore === null) {
            return $query;
        }

        return $query->where('trust_score', '>=', $minScore);
    }

    public function scopeTrustScoreMax(Builder $query, mixed $maxScore): Builder
    {
        if ($maxScore === null) {
            return $query;
        }

        return $query->where('trust_score', '<=', $maxScore);
    }

    public function scopeSearch(Builder $query, mixed $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $likeTerm = SearchTermEscaper::escape((string) $search);

        return $query->where(function (Builder $builder) use ($likeTerm): void {
            $builder->whereRaw("name LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("address LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("city LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("neighborhood LIKE ? ESCAPE '!'", [$likeTerm]);
        });
    }

    public function scopeOpenNow(Builder $query, mixed $openNow): Builder
    {
        $isOpenNow = filter_var($openNow, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isOpenNow !== true) {
            return $query;
        }

        $now = now();
        $dayOfWeek = mb_strtolower($now->englishDayOfWeek);
        $time = $now->format('H:i:s');

        return $query->whereHas('storeHours', fn ($hours) => $hours
            ->where('day_of_week', $dayOfWeek)
            ->where('is_closed', false)
            ->whereNotNull('opens_at')
            ->whereNotNull('closes_at')
            ->where('opens_at', '<=', $time)
            ->where('closes_at', '>=', $time));
    }
}
