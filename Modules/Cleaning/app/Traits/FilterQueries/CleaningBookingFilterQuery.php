<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use App\Enums\GenderPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;
use Modules\User\Services\UserCleaningOrderEstimationService;
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
                AllowedFilter::scope('assignedToCurrentWorker'),
                AllowedFilter::scope('workerView'),
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
                $normalized = mb_trim($status);
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
            return $query->where('id', -1);
        }

        $worker->loadMissing('deposit');
        $canReceiveNewRequests = app(DepositService::class)->isWorkerEligibleForNewRequests($worker);

        $preferredWorkTypes = $worker->preferredWorkTypes();
        $discoverableBookingIds = app(\Modules\Cleaning\Services\CleaningGeographicDispatchService::class)
            ->discoverableBookingIds($worker);

        // A declined offer is worker-scoped, not a cancellation of the booking.
        // Exclude it from *all* branches, including legacy worker_id and old
        // assignment/history branches, so it cannot reappear after refresh.
        return $query
            ->whereDoesntHave('rejections', fn (Builder $rejections) => $rejections->where('worker_id', $worker->id))
            ->where(function (Builder $q) use ($worker, $preferredWorkTypes, $canReceiveNewRequests, $discoverableBookingIds): void {
            $q->where('worker_id', $worker->id)
                ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->orWhereHas('sessions.workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->orWhere(function (Builder $preferred) use ($worker, $canReceiveNewRequests): void {
                    if (! $canReceiveNewRequests) {
                        $preferred->where('id', -1);

                        return;
                    }

                    $preferred->where('status', CleaningBookingStatus::Pending)
                        ->whereNull('worker_id')
                        ->where('preferred_worker_id', $worker->id)
                        ->whereDoesntHave('rejections', fn (Builder $rejections) => $rejections->where('worker_id', $worker->id));
                })
                ->orWhere(function (Builder $pending) use ($worker, $preferredWorkTypes, $canReceiveNewRequests, $discoverableBookingIds): void {
                    if (! $canReceiveNewRequests) {
                        $pending->where('id', -1);

                        return;
                    }

                    $pending->whereIn('id', $discoverableBookingIds)
                        ->where('status', CleaningBookingStatus::Pending)
                        ->whereNull('worker_id')
                        ->whereNull('preferred_worker_id')
                        ->where(function (Builder $scope) use ($worker): void {
                            $scope->whereNull('worker_scope')
                                ->orWhere('worker_scope', '!=', CleaningBooking::WORKER_SCOPE_SPECIFIC)
                                ->orWhereJsonContains('specific_worker_ids', [(int) $worker->id]);
                        })
                        ->where(function (Builder $typeQuery) use ($preferredWorkTypes): void {
                            $hasType = false;
                            if (in_array('cleaning', $preferredWorkTypes, true)) {
                                $hasType = true;
                                $typeQuery->orWhere(function (Builder $cleaning): void {
                                    $cleaning->where('booking_kind', '!=', 'open_time')
                                        ->where('property_type', '!=', UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE);
                                });
                            }
                            if (in_array('events', $preferredWorkTypes, true)) {
                                $hasType = true;
                                $typeQuery->orWhere(function (Builder $events): void {
                                    $events->where('booking_kind', '!=', 'open_time')
                                        ->where('property_type', UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE);
                                });
                            }
                            if (in_array('hourly', $preferredWorkTypes, true)) {
                                $hasType = true;
                                $typeQuery->orWhere('booking_kind', 'open_time');
                            }
                            if (! $hasType) {
                                $typeQuery->where('id', -1);
                            }
                        })
                        ->where(function (Builder $genderQuery) use ($worker): void {
                            $genderQuery
                                ->whereNull('gender_preference')
                                ->orWhere('gender_preference', GenderPreference::Any->value)
                                ->orWhere('gender_preference', $worker->gender);
                        })
                        ->whereDoesntHave('rejections', fn (Builder $rejections) => $rejections->where('worker_id', $worker->id));
                })
                ->orWhere(function (Builder $history) use ($worker): void {
                    // Once a team booking ends, a cancelled assignment may no
                    // longer have an accepted status. Keep true participants in
                    // their history, but never expose offers they never took.
                    $history->whereIn('status', [
                        CleaningBookingStatus::Completed->value,
                        CleaningBookingStatus::PartiallyCompleted->value,
                        CleaningBookingStatus::Cancelled->value,
                    ])->where(function (Builder $participated) use ($worker): void {
                        $participated->whereHas('workerAssignments', fn (Builder $a) => $a
                            ->where('worker_id', $worker->id)->whereNotNull('accepted_at'))
                            ->orWhereHas('sessions.workerAssignments', fn (Builder $a) => $a
                                ->where('worker_id', $worker->id)->whereNotNull('accepted_at'));
                    });
                });
            });
    }

    public function scopeAssignedToCurrentWorker(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        $worker = auth()->user()?->worker;

        if (! $worker) {
            return $query->where('id', -1);
        }

        return $query->where(function (Builder $assigned) use ($worker): void {
            $assigned->where('worker_id', $worker->id)
                ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->orWhereHas('sessions.workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })->orWhere(function (Builder $history) use ($worker): void {
                    $history->whereIn('status', [
                        CleaningBookingStatus::Completed->value,
                        CleaningBookingStatus::PartiallyCompleted->value,
                        CleaningBookingStatus::Cancelled->value,
                    ])->where(function (Builder $participated) use ($worker): void {
                        $participated->whereHas('workerAssignments', fn (Builder $a) => $a
                            ->where('worker_id', $worker->id)->whereNotNull('accepted_at'))
                            ->orWhereHas('sessions.workerAssignments', fn (Builder $a) => $a
                                ->where('worker_id', $worker->id)->whereNotNull('accepted_at'));
                    });
                });
        });
    }

    /** Worker-centric group selection; the stored booking status is unchanged. */
    public function scopeWorkerView(Builder $query, string $view): Builder
    {
        $worker = auth()->user()?->worker;
        if (! $worker) {
            return $query->where('id', -1);
        }

        if ($view === 'mine') {
            return $this->scopeAssignedToCurrentWorker($query, true);
        }

        if ($view !== 'available') {
            return $query->where('id', -1);
        }

        $workerId = (int) $worker->id;

        return $query->where('status', CleaningBookingStatus::Pending->value)
            ->where(function (Builder $bookings) use ($workerId): void {
                $bookings->whereNull('worker_id')->orWhere('worker_id', '!=', $workerId);
            })
            ->whereDoesntHave('workerAssignments', function (Builder $assignments) use ($workerId): void {
                $assignments->where('worker_id', $workerId)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->whereDoesntHave('sessions.workerAssignments', function (Builder $assignments) use ($workerId): void {
                $assignments->where('worker_id', $workerId)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
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
