<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait UserFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
                AllowedFilter::exact('email_verified_at'),
                AllowedFilter::partial('password'),
                AllowedFilter::partial('rememberToken', 'remember_token'),
                AllowedFilter::scope('createdAfter'),
                AllowedFilter::scope('createdBefore'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('email'),
                AllowedSort::field('emailVerifiedAt', 'email_verified_at'),
                AllowedSort::field('password'),
                AllowedSort::field('rememberToken', 'remember_token'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeCreatedAfter($query, $date)
    {
        return $query->where('created_at', '>=', $date);
    }

    public function scopeCreatedBefore($query, $date)
    {
        return $query->where('created_at', '<=', $date);
    }

    public function scopeSearch($query, $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $likeTerm = SearchTermEscaper::escape($search);

        return $query->where(function (Builder $q) use ($likeTerm) {
            $q->whereRaw("name LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("password LIKE ? ESCAPE '!'", [$likeTerm])
                ->orWhereRaw("remember_token LIKE ? ESCAPE '!'", [$likeTerm]);
        });
    }
}
