<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
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
                AllowedFilter::scope('forCurrentWorker'),
                AllowedFilter::scope('pending'),
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

    public function scopeForCurrentWorker(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        $worker = auth()->user()?->worker;

        if (! $worker) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('booking_type', 'cleaning_booking')
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $q) use ($worker) {
                $q->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignmentQuery) use ($worker) {
                        $assignmentQuery
                            ->where('worker_id', $worker->id)
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                    });
            });
    }

    public function scopePending(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereNull('worker_responded_at');
    }
}
