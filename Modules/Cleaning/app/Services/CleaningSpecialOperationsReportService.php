<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningSpecialOperationsReportService
{
    /** @return array<string,mixed> */
    public function overview(): array
    {
        // These are recorded catalog charges, not cash receipts. Financial
        // settlement continues to be sourced from the canonical ledger.
        $lines = CleaningBookingSpecialService::query();
        $quoted = round((float) (clone $lines)->sum('total_price'), 2);
        $itemTotals = round((float) DB::table('cleaning_booking_special_service_items')
            ->sum('total_price'), 2);
        $completed = round((float) CleaningBookingSpecialService::query()
            ->where('execution_status', 'completed')
            ->sum('total_price'), 2);

        $statusGroups = CleaningEquipmentReservation::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all();

        $assetStatuses = CleaningSpecialServiceEquipment::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all();

        $workerIds = DB::table('cleaning_worker_special_service_skills as skills')
            ->join('workers as w', 'w.id', '=', 'skills.worker_id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('skills.is_active', true)->whereNotNull('skills.approved_at')
            ->where('w.is_active', true)->where('u.is_active', true)
            ->where(fn ($q) => $q->whereNull('w.is_suspended')->orWhere('w.is_suspended', false))
            ->distinct()->pluck('skills.worker_id')->map(fn ($id): int => (int) $id)->values()->all();
        $load = app(CleaningSpecialistFairnessRankingService::class)
            ->recentAcceptedSpecialistWorkload($workerIds);
        $names = Worker::query()->whereIn('id', $workerIds)
            ->with('user')->get()->keyBy('id');
        $offers = $this->recordedSpecialistOffers($names, max(1, (int) config('cleaning_specialist_planning.fairness_lookback_days', 30)));

        $workers = collect($workerIds)->map(function (int $workerId) use ($names, $load, $offers): array {
            $worker = $names->get($workerId);
            return [
                'workerId' => $workerId,
                'name' => (string) ($worker?->user?->name ?? $worker?->first_name ?? ('#'.$workerId)),
                'recentAcceptedSlots' => (int) ($load[$workerId]['count'] ?? 0),
                'recordedSpecialistOffers' => (int) ($offers[$workerId] ?? 0),
                'latestAcceptance' => $load[$workerId]['last'] ?? null,
            ];
        })->sort(fn (array $a, array $b): int =>
            ($a['recentAcceptedSlots'] <=> $b['recentAcceptedSlots'])
            ?: strcmp($a['latestAcceptance'] ?? '', $b['latestAcceptance'] ?? '')
            ?: ($a['workerId'] <=> $b['workerId'])
        )->values()->all();

        $catalogLines = CleaningBookingSpecialService::query()
            ->selectRaw('cleaning_special_service_id, service_name, COUNT(*) as bookings_count, SUM(total_price) as recorded_charges')
            ->groupBy('cleaning_special_service_id', 'service_name')
            ->orderByDesc('recorded_charges')->limit(20)->get()->map(fn ($row): array => [
                'serviceId' => (int) $row->cleaning_special_service_id,
                'name' => (string) $row->service_name,
                'lines' => (int) $row->bookings_count,
                'recordedCharges' => round((float) $row->recorded_charges, 2),
            ])->all();

        return [
            'financial' => [
                'recordedSpecialServiceCharges' => $quoted,
                'recordedCompletedServiceCharges' => $completed,
                'itemizedChargeTotal' => $itemTotals,
                'lineItemReconciliationDifference' => round($quoted - $itemTotals, 2),
                'canonicalFinancialSummary' => app(CleaningFinancialSummaryService::class)->global(),
            ],
            'specialServices' => [
                'lines' => (int) CleaningBookingSpecialService::query()->count(),
                'completedLines' => (int) CleaningBookingSpecialService::query()
                    ->where('execution_status', 'completed')->count(),
                'byService' => $catalogLines,
            ],
            'workerOpportunities' => [
                'lookbackDays' => max(1, (int) config('cleaning_specialist_planning.fairness_lookback_days', 30)),
                'eligibleSpecialistsWithApprovedSkill' => count($workerIds),
                'recentAcceptedWorkBySpecialist' => $workers,
                'definition' => 'Recorded specialist booking notifications count unique worker/booking offers; delivery or user reads are not verified. Accepted specialist slots count confirmed assignment records separately.',
            ],
            'equipment' => [
                'assets' => array_sum($assetStatuses),
                'assetStatuses' => $assetStatuses,
                'reservationStatuses' => $statusGroups,
                'reservationCount' => array_sum($statusGroups),
                'pendingAdministrativeReturns' => (int) (($statusGroups['return_pending_confirmation'] ?? 0) + ($statusGroups['failure_pending_confirmation'] ?? 0)),
                'maintenanceOrBrokenAssets' => (int) (($assetStatuses['maintenance'] ?? 0) + ($assetStatuses['broken'] ?? 0)),
                'maintenanceAssets' => CleaningSpecialServiceEquipment::query()
                    ->whereIn('status', ['maintenance', 'broken'])
                    ->orderBy('id')->limit(50)
                    ->get(['id', 'name', 'asset_code', 'status', 'last_handed_over_at', 'last_returned_at'])
                    ->map(fn ($asset): array => [
                        'name' => (string) $asset->name,
                        'assetCode' => (string) ($asset->asset_code ?? ''),
                        'status' => (string) $asset->status,
                        'lastReturnedAt' => $asset->last_returned_at?->toDateTimeString(),
                    ])->all(),
            ],
        ];
    }

    /**
     * Count recorded worker notifications rather than treating acceptance as an invitation.
     * Each worker/booking is counted once, including retried dispatches.
     *
     * @param \Illuminate\Support\Collection<int,Worker> $workers
     * @return array<int,int>
     */
    private function recordedSpecialistOffers(\Illuminate\Support\Collection $workers, int $days): array
    {
        $userToWorker = $workers->filter(fn ($worker): bool => (int) $worker->user_id > 0)
            ->mapWithKeys(fn ($worker): array => [(int) $worker->user_id => (int) $worker->id])
            ->all();
        if ($userToWorker === []) {
            return [];
        }

        $notifications = DB::table('notifications')
            ->where('type', NewOrderRequestNotification::class)
            ->whereIn('notifiable_id', array_keys($userToWorker))
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['notifiable_id', 'data']);

        $candidates = [];
        foreach ($notifications as $notification) {
            $payload = json_decode((string) $notification->data, true);
            $bookingId = (int) ($payload['bookingId'] ?? $payload['orderId'] ?? 0);
            $workerId = $userToWorker[(int) $notification->notifiable_id] ?? null;
            if ($workerId !== null && $bookingId > 0) {
                $candidates[$bookingId][$workerId] = true;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $specialBookingIds = DB::table('cleaning_booking_special_services')
            ->whereIn('cleaning_booking_id', array_keys($candidates))
            ->distinct()->pluck('cleaning_booking_id')->all();

        $result = [];
        foreach ($specialBookingIds as $bookingId) {
            foreach (array_keys($candidates[(int) $bookingId] ?? []) as $workerId) {
                $result[$workerId] = ($result[$workerId] ?? 0) + 1;
            }
        }

        return $result;
    }
}
