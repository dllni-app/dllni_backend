<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait CleaningTimeWarningFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(CleaningTimeWarning::class)
            ->allowedFilters([
                AllowedFilter::exact('bookingId', 'booking_id'),
                AllowedFilter::exact('bookingType', 'booking_type'),
                AllowedFilter::scope('sentAtFrom'),
                AllowedFilter::scope('sentAtTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('sentAt', 'sent_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-sent_at');
    }

    public function scopeSentAtFrom(Builder $query, string $date): Builder
    {
        return $query->where('sent_at', '>=', $date);
    }

    public function scopeSentAtTo(Builder $query, string $date): Builder
    {
        return $query->where('sent_at', '<=', $date);
    }
}
