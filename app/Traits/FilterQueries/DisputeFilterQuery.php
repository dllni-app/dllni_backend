<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\Dispute;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait DisputeFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Dispute::class)
            ->allowedFilters([
                AllowedFilter::callback('forCurrentWorker', function ($query, $value): void {
                    if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        return;
                    }

                    $workerId = Auth::user()?->worker?->id;

                    if (! $workerId) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where('booking_type', 'cleaning_booking')
                        ->whereHasMorph(
                            'booking',
                            [CleaningBooking::class],
                            fn($bookingQuery) => $bookingQuery->where('worker_id', $workerId)
                        );
                }),
                AllowedFilter::exact('bookingId', 'booking_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category'),
                AllowedFilter::exact('bookingType', 'booking_type'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('status'),
            ])
            ->defaultSort('-created_at');
    }
}
