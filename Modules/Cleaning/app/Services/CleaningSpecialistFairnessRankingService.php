<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;

final class CleaningSpecialistFairnessRankingService
{
    /**
     * Fair ranking is only applied AFTER full eligibility and customer scope checks.
     * It cannot bypass a specialist skill/equipment permission or worker availability.
     *
     * @param Collection<int, Worker> $eligibleWorkers
     * @return Collection<int, Worker>
     */
    public function rank(Collection $eligibleWorkers): Collection
    {
        if (! (bool) config('cleaning_specialist_planning.fairness_enabled', true) || $eligibleWorkers->count() < 2) {
            return $eligibleWorkers->values();
        }

        $ids = $eligibleWorkers->pluck('id')->map(fn ($id): int => (int) $id)->unique()->all();
        $counts = $this->recentAcceptedSpecialistWorkload($ids);

        return $eligibleWorkers->sort(function (Worker $a, Worker $b) use ($counts): int {
            $one = $counts[(int) $a->id] ?? ['count' => 0, 'last' => ''];
            $two = $counts[(int) $b->id] ?? ['count' => 0, 'last' => ''];

            // Fewer accepted specialist slots in the rolling window first.
            $countComparison = $one['count'] <=> $two['count'];
            if ($countComparison !== 0) {
                return $countComparison;
            }

            // Least recently allocated wins ties; never allocated workers come first.
            $recencyComparison = strcmp($one['last'], $two['last']);
            if ($recencyComparison !== 0) {
                return $recencyComparison;
            }

            $ratingComparison = (float) ($b->average_rating ?? 0) <=> (float) ($a->average_rating ?? 0);
            return $ratingComparison !== 0 ? $ratingComparison : ((int) $a->id <=> (int) $b->id);
        })->values();
    }

    /** @param array<int,int> $workerIds @return array<int,array{count:int,last:string}> */
    public function recentAcceptedSpecialistWorkload(array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }

        $since = now()->subDays(max(1, (int) config('cleaning_specialist_planning.fairness_lookback_days', 30)));
        $statuses = CleaningBookingWorkerAssignmentStatus::acceptedValues();
        $result = [];

        foreach ([
            ['cleaning_booking_worker_assignments', 'cleaning_booking_id'],
            ['cleaning_booking_session_worker_assignments', 'cleaning_booking_session_id'],
        ] as [$table, $foreignKey]) {
            $query = DB::table($table.' as a')->whereIn('a.worker_id', $workerIds)
                ->whereIn('a.status', $statuses)
                ->where('a.accepted_at', '>=', $since);

            if ($foreignKey === 'cleaning_booking_id') {
                $query->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('cleaning_booking_special_services as s')
                        ->whereColumn('s.cleaning_booking_id', 'a.cleaning_booking_id');
                });
            } else {
                $query->join('cleaning_booking_sessions as sessions', 'sessions.id', '=', 'a.cleaning_booking_session_id')
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')->from('cleaning_booking_special_services as s')
                            ->whereColumn('s.cleaning_booking_id', 'sessions.cleaning_booking_id');
                    });
            }

            foreach ($query->selectRaw('a.worker_id, COUNT(*) as total, MAX(a.accepted_at) as latest')
                ->groupBy('a.worker_id')->get() as $row) {
                $id = (int) $row->worker_id;
                $result[$id] ??= ['count' => 0, 'last' => ''];
                $result[$id]['count'] += (int) $row->total;
                $result[$id]['last'] = max($result[$id]['last'], (string) $row->latest);
            }
        }

        return $result;
    }
}
