<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait CleaningBookingFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(CleaningBooking::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::scope('scheduledDateFrom'),
                AllowedFilter::scope('scheduledDateTo'),
                AllowedFilter::exact('customerId', 'customer_id'),
                AllowedFilter::exact('workerId', 'worker_id'),
                AllowedFilter::scope('hasDispute'),
            ])
            ->allowedSorts([
                AllowedSort::field('scheduledDate', 'scheduled_date'),
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('status'),
                AllowedSort::field('totalPrice', 'total_price'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeScheduledDateFrom(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '>=', $date);
    }

    public function scopeScheduledDateTo(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '<=', $date);
    }

    public function scopeHasDispute(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereHas('disputes');
    }
}
