<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmOrder;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOrderFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOrder::class)
            ->allowedFilters([
                AllowedFilter::exact('customerId', 'customer_id'),
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('pickupMode', 'pickup_mode'),
                AllowedFilter::partial('orderNumber', 'order_number'),
                AllowedFilter::scope('createdAfter'),
                AllowedFilter::scope('createdBefore'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('orderNumber', 'order_number'),
                AllowedSort::field('totalAmount', 'total_amount'),
                AllowedSort::field('status'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeCreatedAfter($query, string $date): Builder
    {
        return $query->whereDate('created_at', '>=', $date);
    }

    public function scopeCreatedBefore($query, string $date): Builder
    {
        return $query->whereDate('created_at', '<=', $date);
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("order_number LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("cancellation_reason LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("special_instructions LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
