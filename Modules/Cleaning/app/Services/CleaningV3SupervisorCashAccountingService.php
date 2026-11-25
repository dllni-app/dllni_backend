<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Documentary accounting only: the supervisor handles physical cash, never this service. */
final class CleaningV3SupervisorCashAccountingService
{
    private const TABLE = 'cleaning_v3_supervisor_cash_accounts';
    private const EARNINGS = 'cleaning_v3_supervisor_cash_earnings';

    public function review(int $bookingId, int $visitId, User $actor): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor) {
            abort_unless(CleaningV3CashSettlementService::authorized() || ($actor->hasRole(SupervisedTeamService::ROLE)
                && $actor->is_active && DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $bookingId)
                    ->where('supervisor_user_id', $actor->id)->exists()), 404);
            [$booking, $visit, $calc, $digest] = $this->lockedCalculation($bookingId, $visitId);
            abort_unless(CleaningV3CashSettlementService::authorized() || ((int) $actor->id === $calc['collectorUserId'] && $actor->hasRole(SupervisedTeamService::ROLE) && $actor->is_active), 404);
            return $this->project($visit, $calc, $digest);
        }, 3);
    }

    public function report(int $bookingId, int $visitId, User $actor, string $amount, string $reference, string $evidence): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        $this->enabled();
        $this->evidence($reference, $evidence);
        abort_unless(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $bookingId)
            ->where('supervisor_user_id', $actor->id)->exists(), 404);
        return DB::transaction(function () use ($bookingId, $visitId, $actor, $amount, $reference, $evidence) {
            [$booking, $visit, $calc, $digest] = $this->lockedCalculation($bookingId, $visitId);
            abort_unless((int) $actor->id === $calc['collectorUserId'], 404);
            $state = $this->project($visit, $calc, $digest);
            if ($amount !== $state['customerAmountSyp'] || $calc['unfundedSyp'] !== '0.00') {
                throw new DomainException('V3_SUPERVISOR_CASH_REVIEW_REQUIRED');
            }
            $existing = DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->report_reference !== $reference || $existing->report_evidence !== $evidence) {
                    throw new DomainException('V3_SUPERVISOR_CASH_ALREADY_REPORTED');
                }
                return $state;
            }
            if ($visit->payment_status === 'settled' || DB::table(self::TABLE)->where('report_reference', $reference)->exists()) {
                throw new DomainException('V3_SUPERVISOR_CASH_REPORT_CONFLICT');
            }
            $id = DB::table(self::TABLE)->insertGetId([
                'visit_id' => $visitId, 'collector_user_id' => $actor->id, 'calculation_digest' => $digest,
                'customer_amount' => $state['customerAmountSyp'], 'worker_amount' => $state['workerAmountSyp'],
                'supervisor_amount' => $state['supervisorAmountSyp'], 'company_amount' => $state['companyAmountSyp'],
                'report_reference' => $reference, 'report_evidence' => $evidence,
                'reported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($calc['supervisorPayables'] as $key => $payable) {
                DB::table(self::EARNINGS)->insert(['account_id' => $id, 'supervisor_user_id' => (int) substr($key, 11), 'amount' => $payable]);
            }
            return $this->project($visit, $calc, $digest);
        }, 3);
    }

    public function receive(int $bookingId, int $visitId, string $amount, string $reference, string $evidence): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        $this->enabled();
        $this->evidence($reference, $evidence);
        return DB::transaction(function () use ($bookingId, $visitId, $amount, $reference, $evidence) {
            [$booking, $visit, $calc, $digest] = $this->lockedCalculation($bookingId, $visitId);
            $state = $this->project($visit, $calc, $digest);
            $row = DB::table(self::TABLE)->where('visit_id', $visitId)->lockForUpdate()->first();
            if ($row === null || (int) auth()->id() === (int) $row->collector_user_id || $amount !== $state['companyAmountSyp']) {
                throw new DomainException('V3_SUPERVISOR_CASH_RECEIPT_MISMATCH');
            }
            if ($row->received_at !== null) {
                if ($row->receipt_reference !== $reference || $row->receipt_evidence !== $evidence) {
                    throw new DomainException('V3_SUPERVISOR_CASH_ALREADY_RECEIVED');
                }
                return $state;
            }
            if ($visit->payment_status === 'settled' || DB::table(self::TABLE)->where('receipt_reference', $reference)->exists()) {
                throw new DomainException('V3_SUPERVISOR_CASH_RECEIPT_CONFLICT');
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'receipt_reference' => $reference, 'receipt_evidence' => $evidence,
                'received_by' => auth()->id(), 'received_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($calc['workerPayables'] as $payable) {
                $visit->workerAssignments()->where('worker_id', $payable['workerId'])->firstOrFail()->forceFill([
                    'service_share_amount' => $payable['serviceShareSyp'], 'travel_fee' => $payable['travelSyp'],
                    'worker_amount' => $payable['grossPayableSyp'], 'admin_margin_amount' => '0.00',
                ])->saveQuietly();
            }
            $visit->forceFill(['payment_status' => 'settled', 'payment_settled_at' => now(),
                'admin_margin_amount' => $calc['companyNetCommissionSyp']])->saveQuietly();
            return $this->project($visit, $calc, $digest);
        }, 3);
    }

    public function adminSummary(): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        $rows = DB::table(self::TABLE.' as a')->join('users as u', 'u.id', '=', 'a.collector_user_id')
            ->select(['a.collector_user_id', 'u.name'])
            ->selectRaw('SUM(a.customer_amount) as collected, SUM(a.worker_amount) as workers')
            ->selectRaw('SUM(CASE WHEN a.received_at IS NULL THEN a.company_amount ELSE 0 END) as outstanding')
            ->selectRaw('SUM(CASE WHEN a.received_at IS NOT NULL THEN a.company_amount ELSE 0 END) as delivered')
            ->groupBy('a.collector_user_id', 'u.name')->orderByDesc('outstanding')->get();
        return $rows->map(fn ($row) => ['supervisorId' => (int) $row->collector_user_id, 'name' => $row->name,
            'reportedCollectedSyp' => bcadd((string) $row->collected, '0', 2),
            'reportedWorkerPaymentsSyp' => bcadd((string) $row->workers, '0', 2),
            'companyOutstandingSyp' => bcadd((string) $row->outstanding, '0', 2),
            'companyDeliveredSyp' => bcadd((string) $row->delivered, '0', 2)])->all();
    }

    /** Personal statistics from reported cash only, not estimates or other supervisors' ledgers. */
    public function statistics(User $actor): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        return DB::transaction(function () use ($actor) {
            $totals = array_fill_keys(['reportedCollectedSyp', 'reportedWorkerPaymentsSyp', 'ownReportedEarningsSyp',
                'ownConfirmedEarningsSyp', 'companyOutstandingSyp', 'companyDeliveredSyp'], '0.00');
            $rows = DB::table(self::TABLE)->where('collector_user_id', $actor->id)->orderByDesc('id')->get();
            foreach ($rows as $row) {
                $totals['reportedCollectedSyp'] = bcadd($totals['reportedCollectedSyp'], (string) $row->customer_amount, 2);
                $totals['reportedWorkerPaymentsSyp'] = bcadd($totals['reportedWorkerPaymentsSyp'], (string) $row->worker_amount, 2);
                $key = $row->received_at === null ? 'companyOutstandingSyp' : 'companyDeliveredSyp';
                $totals[$key] = bcadd($totals[$key], (string) $row->company_amount, 2);
            }
            $earnings = DB::table(self::EARNINGS.' as e')->join(self::TABLE.' as a', 'a.id', '=', 'e.account_id')
                ->where('e.supervisor_user_id', $actor->id)->get(['e.amount', 'a.received_at']);
            foreach ($earnings as $entry) {
                $totals['ownReportedEarningsSyp'] = bcadd($totals['ownReportedEarningsSyp'], (string) $entry->amount, 2);
                if ($entry->received_at !== null) {
                    $totals['ownConfirmedEarningsSyp'] = bcadd($totals['ownConfirmedEarningsSyp'], (string) $entry->amount, 2);
                }
            }
            // The reportable visits list deliberately excludes older cash policies.
            $visits = CleaningBookingSession::query()->where('status', 'completed')->where('supervision_mode', 'supervisor')
                ->whereHas('booking', fn ($q) => $q->where('contract_version', 'v3')->whereHas('supervisorAssignments',
                    fn ($a) => $a->where('supervisor_user_id', $actor->id)))
                ->whereNotIn('id', DB::table(self::TABLE)->select('visit_id'))
                ->orderByDesc('id')->limit(100)->get();
            $pending = [];
            foreach ($visits as $visit) {
                if (data_get($visit->pricing_snapshot, 'settlementBasis.commissionAdded') === true) {
                    $pending[] = ['bookingId' => (int) $visit->cleaning_booking_id, 'visitId' => (int) $visit->id];
                }
            }
            return ['currency' => 'SYP', ...$totals, 'unreportedVisits' => $pending,
                'reports' => $rows->take(100)->map(fn ($row) => ['visitId' => (int) $row->visit_id,
                    'customerAmountSyp' => bcadd((string) $row->customer_amount, '0', 2),
                    'companyAmountSyp' => bcadd((string) $row->company_amount, '0', 2),
                    'status' => $row->received_at === null ? 'reported' : 'received'])->values()->all()];
        });
    }

    /** Called with the booking and visit already locked. */
    public function project(CleaningBookingSession $visit, array $calc, string $digest): array
    {
        $row = DB::table(self::TABLE)->where('visit_id', $visit->id)->lockForUpdate()->first();
        $worker = array_reduce($calc['workerPayables'], fn ($sum, $p) => bcadd($sum, $p['grossPayableSyp'], 2), '0.00');
        $names = \App\Models\Worker::query()->whereIn('id', array_column($calc['workerPayables'], 'workerId'))
            ->with('user:id,name')->get()->keyBy('id');
        $workerPayments = array_map(fn ($payable) => ['workerId' => $payable['workerId'],
            'name' => $names->get($payable['workerId'])?->user?->name ?? ('#'.$payable['workerId']),
            'amountSyp' => $payable['grossPayableSyp']], $calc['workerPayables']);
        $state = ['visitId' => (int) $visit->id, 'collectorUserId' => $calc['collectorUserId'],
            'workerPayments' => $workerPayments,
            'customerAmountSyp' => $calc['totalDueSyp'], 'workerAmountSyp' => $worker,
            'supervisorAmountSyp' => $calc['supervisorPayableSyp'], 'companyAmountSyp' => $calc['companyCashDueSyp'],
            'supervisorPayables' => $calc['supervisorPayables'], 'companyNetCommissionSyp' => $calc['companyNetCommissionSyp'],
            'fundingGapSyp' => $calc['unfundedSyp'], 'status' => 'awaiting_report'];
        if (($visit->payment_status === 'settled') !== ($row !== null && $row->received_at !== null)
            || DB::table('cleaning_v3_cash_movements')->where('cleaning_booking_session_id', $visit->id)->exists()) {
            throw new DomainException('V3_SUPERVISOR_CASH_RECONCILIATION_REQUIRED');
        }
        if ($row !== null) {
            if (! hash_equals($row->calculation_digest, $digest) || (int) $row->collector_user_id !== $calc['collectorUserId']
                || bccomp((string) $row->customer_amount, $state['customerAmountSyp'], 2) !== 0
                || bccomp((string) $row->worker_amount, $worker, 2) !== 0
                || bccomp((string) $row->supervisor_amount, $state['supervisorAmountSyp'], 2) !== 0
                || bccomp((string) $row->company_amount, $state['companyAmountSyp'], 2) !== 0) {
                throw new DomainException('V3_SUPERVISOR_CASH_RECONCILIATION_REQUIRED');
            }
            $state['status'] = $row->received_at === null ? 'reported' : 'received';
        }
        return $state;
    }

    private function lockedCalculation(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        $visit->setRelation('booking', $booking);
        [$quote, $calc, $digest] = app(CleaningV3CashSettlementService::class)->manualTeamCalculation($booking, $visit);
        return [$booking, $visit, $calc, $digest];
    }

    private function evidence(string $reference, string $evidence): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $reference) !== 1
            || mb_strlen(trim($evidence)) < 10 || mb_strlen($evidence) > 500) {
            throw new DomainException('V3_SUPERVISOR_CASH_EVIDENCE_REQUIRED');
        }
    }

    private function enabled(): void
    {
        if (! config('cleaning_v3_catalog.cash_settlement_enabled', false)) {
            throw new DomainException('V3_CASH_SETTLEMENT_PAUSED');
        }
    }
}
