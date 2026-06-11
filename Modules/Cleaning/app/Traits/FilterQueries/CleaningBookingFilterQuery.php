<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use App\Enums\GenderPreference;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
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
                AllowedFilter::scope('status'),
                AllowedFilter::scope('scheduledDateFrom'),
                AllowedFilter::scope('scheduledDateTo'),
                AllowedFilter::scope('scheduledDate'),
                AllowedFilter::exact('customerId', 'customer_id'),
                AllowedFilter::exact('workerId', 'worker_id'),
                AllowedFilter::exact('propertyType', 'property_type'),
                AllowedFilter::scope('forCurrentWorker'),
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

    public function scopeStatus(Builder $query, string ...$values): Builder
    {
        $statuses = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $status) {
                $normalized = trim($status);
                if ($normalized === '') {
                    continue;
                }

                $statuses[] = $normalized;
            }
        }

        $statuses = array_values(array_unique($statuses));

        if ($statuses === []) {
            return $query;
        }

        if (count($statuses) === 1) {
            return $query->where('status', $statuses[0]);
        }

        return $query->whereIn('status', $statuses);
    }

    public function scopeScheduledDateFrom(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '>=', $date);
    }

    public function scopeScheduledDateTo(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '<=', $date);
    }

    public function scopeScheduledDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_date', $date);
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

        return $query->where(function (Builder $q) use ($worker): void {
            $q->where('worker_id', $worker->id)
                ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->orWhere(function (Builder $pending) use ($worker): void {
                    $pending->where('status', CleaningBookingStatus::Pending)
                        ->whereNull('worker_id')
                        ->where(function (Builder $genderQuery) use ($worker): void {
                            $genderQuery
                                ->whereNull('gender_preference')
                                ->orWhere('gender_preference', GenderPreference::Any->value)
                                ->orWhere('gender_preference', $worker->gender);
                        })
                        ->whereDoesntHave('rejections', fn (Builder $rejections) => $rejections->where('worker_id', $worker->id));
                });
        });
    }

    public function scopeHasDispute(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereHas('disputes');
    }
}
