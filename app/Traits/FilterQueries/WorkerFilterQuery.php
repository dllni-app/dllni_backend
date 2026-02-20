<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait WorkerFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Worker::class)
            ->allowedFilters([
                AllowedFilter::scope('trustScoreMin'),
                AllowedFilter::scope('trustScoreMax'),
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::exact('isSuspended', 'is_suspended'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('trustScore', 'trust_score'),
                AllowedSort::field('firstName', 'first_name'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeTrustScoreMin(Builder $query, int $value): Builder
    {
        return $query->where('trust_score', '>=', $value);
    }

    public function scopeTrustScoreMax(Builder $query, int $value): Builder
    {
        return $query->where('trust_score', '<=', $value);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $likeTerm = SearchTermEscaper::escape($search);

        return $query->where(function (Builder $q) use ($likeTerm) {
            $q->whereRaw("first_name LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->whereRaw("name LIKE ? ESCAPE '!'", [$likeTerm])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", [$likeTerm]));
        });
    }
}
