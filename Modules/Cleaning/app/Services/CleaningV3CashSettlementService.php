<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

/**
 * Cash treasury reconciliation, after service completion only.
 * An authorized cashier records physical cash evidence; a DIFFERENT
 * administrator confirms that the cash was actually counted/paid.
 * This is a manual accounting workflow, not a payment gateway, and never
 * treats a booking, customer confirmation, deposit or quote as actual cash.
 */
final class CleaningV3CashSettlementService
{
    private const TABLE = 'cleaning_v3_cash_movements';

    public static function authorized(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasAnyRole(['admin', 'Super Admin'])
            && \App\Models\User::query()->whereKey($user->id)->where('is_active', true)->exists();
    }

    public function review(int $bookingId, int $visitId): array
    {
        abort_unless(self::authorized(), 403);
        return DB::transaction(function () use ($bookingId, $visitId) {
            [$booking, $visit] = $this->lockVisit($bookingId, $visitId);
            [$quote, $calc, $digest] = $this->calculation($booking, $visit);
            if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
                return app(CleaningV3SupervisorCashAccountingService::class)->project($visit, $calc, $digest);
            }
            return $this->state($visit, $quote, $calc, $digest);
        }, 3);
    }

    /**
     * Worker-owned read projection. Completion alone is not payment, and a
     * pre-start assigned amount is not the final attendance-based entitlement.
     * Reuse the signed reconciliation and expose only this beneficiary's facts.
     */
    public function workerEarning(int $assignmentId, Worker $worker): ?array
    {
        $actor = auth()->user();
        if ($actor === null || ! $actor->is_active || (int) $actor->id !== (int) $worker->user_id) {
            return null;
        }
        $scope = CleaningBookingSessionWorkerAssignment::query()->whereKey($assignmentId)
            ->where('worker_id', $worker->id)->whereNotNull('accepted_at')
            ->with('session.booking')->first();
        $visit = $scope?->session;
        $booking = $visit?->booking;
        if ($booking === null || $booking->contract_version !== 'v3' || $booking->isEventAssistanceBooking()
            || $visit->status !== CleaningBookingSessionStatus::Completed || $scope->work_started_at === null) {
            return null;
        }
        return DB::transaction(function () use ($worker, $assignmentId, $booking, $visit): ?array {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $visit = $booking->sessions()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $visit->setRelation('booking', $booking);
            $assignment = $visit->workerAssignments()->whereKey($assignmentId)->where('worker_id', $worker->id)
                ->whereNotNull('accepted_at')->whereNotNull('work_started_at')->lockForUpdate()->first();
            if ($assignment === null || $visit->status !== CleaningBookingSessionStatus::Completed
                || $booking->contract_version !== 'v3' || $booking->isEventAssistanceBooking()
                || ! app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit)
                || app(CleaningV3DailyBookingProjection::class)->execution($booking)?->id !== $visit->id) {
                return null;
            }
            $result = [
                'key' => 'session_assignment:'.$assignment->id,
                'bookingId' => (int) $booking->id,
                'executionId' => (int) $visit->id,
                'sessionAssignmentId' => (int) $assignment->id,
                'bookingNumber' => $booking->booking_number,
                'scheduledDate' => $visit->scheduled_date?->format('Y-m-d'),
                'currency' => 'SYP',
                'amountSyp' => null,
                'commissionSyp' => null,
                'reconciliationState' => 'awaiting_settlement',
            ];
            if ($visit->payment_status !== 'settled') {
                return $result;
            }
            try {
                if ($visit->payment_settled_at === null || ! $visit->is_pricing_final || $visit->customer_completed_at === null) {
                    throw new DomainException('V3_CASH_COMPLETED_CONFIRMED_VISIT_REQUIRED');
                }
                [$quote, $calc, $digest] = $this->calculation($booking, $visit);
                $settled = ($calc['cashCollectionMode'] ?? null) === 'supervisor_manual'
                    ? app(CleaningV3SupervisorCashAccountingService::class)->project($visit, $calc, $digest)['status'] === 'received'
                    : $this->state($visit, $quote, $calc, $digest)['allPayablesPosted'];
                $payable = collect($calc['workerPayables'])->firstWhere('workerId', (int) $worker->id);
                $commission = ($calc['cashCollectionMode'] ?? null) === 'supervisor_manual'
                    ? '0.00' : ($payable['commissionReceivableSyp'] ?? null);
                if (! $settled || $payable === null || $assignment->currency !== 'SYP'
                    || CleaningV3CashSettlementBasis::cents((string) $assignment->getRawOriginal('worker_amount'))
                        !== CleaningV3CashSettlementBasis::cents($payable['grossPayableSyp'])
                    || CleaningV3CashSettlementBasis::cents((string) $assignment->getRawOriginal('admin_margin_amount'))
                        !== CleaningV3CashSettlementBasis::cents($commission)) {
                    throw new DomainException('V3_WORKER_EARNING_RECONCILIATION_REQUIRED');
                }
                $result['amountSyp'] = $payable['grossPayableSyp'];
                $result['commissionSyp'] = $commission;
                $result['reconciliationState'] = 'settled';
            } catch (DomainException) {
                $result['reconciliationState'] = 'review_required';
            }
            return $result;
        }, 3);
    }

    /** Internal locked calculation for the separate physical-supervisor accounting ledger. */
    public function manualTeamCalculation(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        if (DB::transactionLevel() < 1 || $booking->contract_version !== 'v3'
            || (int) $visit->cleaning_booking_id !== (int) $booking->id
            || $visit->status !== CleaningBookingSessionStatus::Completed || $visit->customer_completed_at === null
            || ! $visit->is_pricing_final || DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->exists()) {
            throw new DomainException('V3_SUPERVISOR_CASH_COMPLETED_VISIT_REQUIRED');
        }
        [$quote, $calc, $digest] = $this->calculation($booking, $visit);
        if (($calc['cashCollectionMode'] ?? null) !== 'supervisor_manual' || ! is_int($calc['collectorUserId'] ?? null)) {
            throw new DomainException('V3_SUPERVISOR_CASH_POLICY_REQUIRED');
        }
        return [$quote, $calc, $digest];
    }

    /** Customer-safe projection: never expose payroll, evidence or treasury references. */
    public function customerSummary(int $bookingId, int $customerId): array
    {
        return DB::transaction(function () use ($bookingId, $customerId) {
            $booking = CleaningBooking::query()->whereKey($bookingId)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            abort_unless($booking->contract_version === 'v3', 404);
            $result = [];
            foreach ($booking->sessions()->orderBy('sequence')->lockForUpdate()->get() as $visit) {
                $visit->setRelation('booking', $booking);
                $row = ['visitId' => (int) $visit->id, 'quotedAmountSyp' => bcadd((string) $visit->getRawOriginal('total_price'), '0', 2),
                    'finalAmountSyp' => null, 'collectedAmountSyp' => null, 'refundedAmountSyp' => null,
                    'currency' => 'SYP', 'status' => 'awaiting_completion'];
                if ($visit->status === CleaningBookingSessionStatus::Cancelled && data_get($booking->property_details, 'v3_admission_hold') === true
                    && ! $visit->workerAssignments()->exists() && ! DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->exists()) {
                    $row = array_replace($row, ['status' => 'cancelled_unpaid', 'finalAmountSyp' => '0.00', 'collectedAmountSyp' => '0.00', 'refundedAmountSyp' => '0.00']);
                } elseif ($visit->status === CleaningBookingSessionStatus::Completed) {
                    try {
                        [$quote, $calc, $digest] = $this->calculation($booking, $visit);
                        if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
                            $manual = app(CleaningV3SupervisorCashAccountingService::class)->project($visit, $calc, $digest);
                            $row = array_replace($row, ['status' => $manual['status'] === 'received' ? 'settled' : 'awaiting_collection',
                                'finalAmountSyp' => $calc['totalDueSyp'],
                                'collectedAmountSyp' => $manual['status'] === 'received' ? $calc['totalDueSyp'] : null,
                                'refundedAmountSyp' => '0.00']);
                            $result[] = $row;
                            continue;
                        }
                        $state = $this->state($visit, $quote, $calc, $digest);
                        $row = array_replace($row, [
                            'status' => $state['paymentStatus'] === 'settled' ? 'settled' : ($state['collectedCents'] > 0 ? 'collected_pending_settlement' : 'awaiting_collection'),
                            'finalAmountSyp' => $calc['totalDueSyp'],
                            'collectedAmountSyp' => CleaningV3CashSettlementBasis::money($state['collectedCents']),
                            'refundedAmountSyp' => CleaningV3CashSettlementBasis::money($state['refundedFeeCents']),
                        ]);
                    } catch (DomainException) {
                        $row['status'] = 'review_required';
                    }
                } elseif ($visit->isTerminal()) {
                    $row['status'] = 'review_required';
                }
                $result[] = $row;
            }
            return $result;
        }, 3);
    }

    public function record(int $bookingId, int $visitId, string $kind, string $beneficiary, string $amount,
        string $reference, string $evidence): array
    {
        abort_unless(self::authorized(), 403);
        $this->assertEnabled();
        $amount = CleaningV3CashSettlementBasis::money(CleaningV3CashSettlementBasis::cents($amount));
        $this->assertReference($reference, $evidence);
        $actor = (int) auth()->id();
        return DB::transaction(function () use ($bookingId, $visitId, $kind, $beneficiary, $amount, $reference, $evidence, $actor) {
            [$booking, $visit] = $this->lockVisit($bookingId, $visitId);
            [$quote, $calc, $digest] = $this->calculation($booking, $visit);
            if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
                throw new DomainException('V3_SUPERVISOR_CASH_ACCOUNTING_REQUIRED');
            }
            $existing = DB::table(self::TABLE)->where('reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->cleaning_booking_session_id !== $visitId || $existing->quote_id !== $quote->id
                    || $existing->kind !== $kind || $existing->beneficiary_key !== $beneficiary
                    || bccomp((string) $existing->amount, $amount, 2) !== 0
                    || $existing->evidence !== $evidence || (int) $existing->recorded_by !== $actor
                    || ! hash_equals($existing->calculation_digest, $digest)) {
                    throw new DomainException('V3_CASH_REFERENCE_CONFLICT');
                }
                return $this->state($visit, $quote, $calc, $digest);
            }
            $state = $this->state($visit, $quote, $calc, $digest);
            $this->assertMovement($state, $kind, $beneficiary, $amount);
            DB::table(self::TABLE)->insert([
                'cleaning_booking_session_id' => $visitId, 'quote_id' => $quote->id,
                'reference' => $reference, 'collection_reference' => in_array($kind, ['collection', 'funding_topup'], true) ? null : $state['collectionReference'],
                'kind' => $kind, 'beneficiary_key' => $beneficiary,
                'amount' => $amount, 'currency' => 'SYP', 'status' => 'pending',
                'evidence' => $evidence, 'calculation_digest' => $digest,
                'calculation' => json_encode($calc, JSON_THROW_ON_ERROR),
                'recorded_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->state($visit, $quote, $calc, $digest);
        }, 3);
    }

    public function verify(int $bookingId, int $visitId, string $reference, string $amount): array
    {
        abort_unless(self::authorized(), 403);
        $this->assertEnabled();
        $amount = CleaningV3CashSettlementBasis::money(CleaningV3CashSettlementBasis::cents($amount));
        $actor = (int) auth()->id();
        return DB::transaction(function () use ($bookingId, $visitId, $reference, $amount, $actor) {
            [$booking, $visit] = $this->lockVisit($bookingId, $visitId);
            [$quote, $calc, $digest] = $this->calculation($booking, $visit);
            if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
                throw new DomainException('V3_SUPERVISOR_CASH_ACCOUNTING_REQUIRED');
            }
            $row = DB::table(self::TABLE)->where('reference', $reference)
                ->where('cleaning_booking_session_id', $visitId)->lockForUpdate()->first();
            if ($row === null || $row->quote_id !== $quote->id || ! hash_equals($row->calculation_digest, $digest)
                || (int) $row->recorded_by === $actor || bccomp((string) $row->amount, $amount, 2) !== 0) {
                throw new DomainException('V3_CASH_INDEPENDENT_VERIFICATION_REQUIRED');
            }
            if ($row->status === 'posted') {
                return $this->state($visit, $quote, $calc, $digest);
            }
            if ($row->status !== 'pending') {
                throw new DomainException('V3_CASH_INVALID_STATE');
            }
            $state = $this->state($visit, $quote, $calc, $digest, (int) $row->id);
            $this->assertMovement($state, $row->kind, $row->beneficiary_key, $amount);
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'posted', 'verified_by' => $actor, 'verified_at' => now(), 'updated_at' => now(),
            ]);
            $state = $this->state($visit, $quote, $calc, $digest);
            if ($state['allPayablesPosted'] && $visit->payment_status !== 'settled') {
                // Only verified physical receipt AND all verified disbursements
                // can close a visit. Commission remains a separate receivable.
                foreach ($calc['workerPayables'] as $payable) {
                    $worker = Worker::query()->whereKey($payable['workerId'])->firstOrFail();
                    $assignment = $visit->workerAssignments()->where('worker_id', $worker->id)
                        ->firstOrFail();
                    $assignment->forceFill([
                        'service_share_amount' => $payable['serviceShareSyp'], 'travel_fee' => $payable['travelSyp'],
                        'worker_amount' => $payable['grossPayableSyp'], 'admin_margin_amount' => $payable['commissionReceivableSyp'],
                    ])->saveQuietly();
                    app(DepositService::class)->recordSessionAdminFeeDebit(
                        $worker, $booking, $visit, (float) $payable['commissionReceivableSyp'], $actor,
                    );
                }
                $visit->forceFill([
                    'payment_status' => 'settled', 'payment_settled_at' => now(),
                    'admin_margin_amount' => $calc['commissionReceivableSyp'],
                ])->saveQuietly();
            }
            return $this->state($visit, $quote, $calc, $digest);
        }, 3);
    }

    private function assertEnabled(): void
    {
        if (! config('cleaning_v3_catalog.cash_settlement_enabled', false)) {
            throw new DomainException('V3_CASH_SETTLEMENT_PAUSED');
        }
    }

    private function assertReference(string $reference, string $evidence): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $reference) !== 1
            || mb_strlen(trim($evidence)) < 10 || mb_strlen($evidence) > 500) {
            throw new DomainException('V3_CASH_EVIDENCE_REQUIRED');
        }
    }

    private function lockVisit(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        if ($booking->contract_version !== 'v3' || $visit->status !== CleaningBookingSessionStatus::Completed
            || ! $visit->is_pricing_final || (int) $visit->price_revision < 1
            || $visit->customer_completed_at === null) {
            throw new DomainException('V3_CASH_COMPLETED_CONFIRMED_VISIT_REQUIRED');
        }
        $visit->setRelation('booking', $booking);
        return [$booking, $visit];
    }

    private function calculation(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        [$quote] = app(CleaningV3AcceptedQuoteResolver::class)->load($booking, 'V3_SETTLEMENT_QUOTE_LINK_REQUIRED');
        // Each applied live extension is a separate signed consent chain.
        // The original quote remains immutable, and unrelated visits retain
        // their original price revisions.
        [$effectiveQuote, $snapshot, $row, $basis, $revision] = app(CleaningV3LiveExtensionService::class)->effectivePrice($booking, $visit);
        if ($effectiveQuote->id !== $quote->id) {
            throw new DomainException('V3_SETTLEMENT_VISIT_PRICE_MISMATCH');
        }
        app(CleaningV3ConfirmedVisitPrice::class)->assertPersisted($visit, $row, $basis, $revision);
        if (($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery' || $row === null
            || $row['appointmentKey'] !== $visit->appointment_key || $row['pricedWorkers'] !== $visit->requiredWorkerCount()
            || $snapshot['pricingRevision'] !== (int) $visit->price_revision
            || bccomp($row['totalAmountSyp'], (string) $visit->getRawOriginal('total_price'), 2) !== 0
            || bccomp((string) ($visit->getRawOriginal('extension_fee_total') ?? 0), '0', 2) !== 0) {
            throw new DomainException('V3_SETTLEMENT_VISIT_PRICE_MISMATCH');
        }
        $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
        if ($clock['state'] !== 'completed' || $clock['activeSince'] !== null || $clock['pausePending']) {
            throw new DomainException('V3_SETTLEMENT_CLOCK_NOT_CLOSED');
        }
        $intervals = DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)
            ->orderBy('started_at')->lockForUpdate()->get();
        $attendance = [];
        $assignments = $visit->workerAssignments()->orderBy('worker_id')->lockForUpdate()->get();
        $edges = [];
        foreach ($assignments as $assignment) {
            if ($assignment->work_started_at === null) {
                continue;
            }
            $status = $assignment->status->value;
            $endAt = $assignment->work_finished_at ?? $assignment->released_at;
            if ($endAt === null || ! in_array($status, ['completed', 'withdrawn', 'cancelled'], true)
                || ($status !== 'completed' && $assignment->released_at === null)
                || $assignment->accepted_at === null) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
            }
            $start = CarbonImmutable::instance($assignment->work_started_at)->utc();
            $end = CarbonImmutable::instance($endAt)->utc();
            if ($assignment->released_at !== null) {
                $end = $end->min(CarbonImmutable::instance($assignment->released_at)->utc());
            }
            if ($end <= $start || $start < CarbonImmutable::instance($assignment->accepted_at)->utc()
                || $end > CarbonImmutable::now('UTC')) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
            }
            $seconds = 0;
            foreach ($intervals as $interval) {
                $a = CarbonImmutable::parse($interval->started_at, 'UTC')->max($start);
                $b = CarbonImmutable::parse($interval->ended_at, 'UTC')->min($end);
                if ($b > $a) {
                    $seconds += (int) $a->diffInSeconds($b, false);
                    $edges[$a->getTimestamp()] = ($edges[$a->getTimestamp()] ?? 0) + 1;
                    $edges[$b->getTimestamp()] = ($edges[$b->getTimestamp()] ?? 0) - 1;
                }
            }
            if ($seconds > 0) {
                $attendance[] = ['workerId' => (int) $assignment->worker_id, 'activeSeconds' => $seconds];
            }
        }
        // Refuse overlapping replacements that would pay more simultaneous
        // workers than the customer-approved crew, even if total seconds fit.
        ksort($edges, SORT_NUMERIC);
        $present = 0;
        foreach ($edges as $delta) {
            $present += $delta;
            if ($present < 0 || $present > $row['pricedWorkers']) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_OVERLAP');
            }
        }
        $mode = $snapshot['settlementBasis']['visits'][$visit->occurrence_key]['billingMode'] ?? null;
        $supervised = $mode === 'task' && $row['pricedWorkers'] > 1;
        if ($supervised !== ($visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)) {
            throw new DomainException('V3_SETTLEMENT_SUPERVISION_MISMATCH');
        }
        $proofs = $supervised ? app(CleaningV3SupervisorAttendanceProofService::class)->verified($visit) : [];
        $manualTeam = ($snapshot['settlementBasis']['visits'][$visit->occurrence_key]['commissionAdded'] ?? false) === true;
        if ($manualTeam && count($proofs) !== 1) {
            throw new DomainException('V3_SUPERVISOR_REPLACEMENT_NOT_ALLOWED');
        }
        $seconds = array_sum(array_column($proofs, 'active_seconds'));
        $calc = app(CleaningV3CashSettlementCalculator::class)->calculate(
            $snapshot, $visit->occurrence_key, $clock['activeSeconds'], $attendance, $supervised ? $seconds : null,
        );
        $supervisorPayables = [];
        $cursor = 0;
        $previous = 0;
        $rate = CleaningV3CashSettlementBasis::cents($snapshot['settlementBasis']['supervisorHourlyCompensation']);
        foreach ($proofs as $proof) {
            $cursor += $proof['active_seconds'];
            $next = (int) bcdiv(bcadd(bcmul((string) $rate, (string) $cursor, 0), '1800', 0), '3600', 0);
            if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
                $next = CleaningV3CashSettlementBasis::cents($calc['supervisorPayableSyp']);
            }
            $key = 'supervisor:'.$proof['supervisor_user_id'];
            $supervisorPayables[$key] = ($supervisorPayables[$key] ?? 0) + $next - $previous;
            $previous = $next;
        }
        $calc['supervisorPayables'] = array_map(CleaningV3CashSettlementBasis::money(...), $supervisorPayables);
        if (($calc['cashCollectionMode'] ?? null) === 'supervisor_manual') {
            $calc['collectorUserId'] = (int) end($proofs)['supervisor_user_id'];
        }
        $calc['supervisorProofReferences'] = array_column($proofs, 'proofReference');
        $calc['supervisorUserId'] = count($proofs) === 1 ? $proofs[0]['supervisor_user_id'] : null;
        $digest = hash('sha256', json_encode([$quote->id, $visit->id, $snapshot['pricingRevision'], $clock['revision'], $calc], JSON_THROW_ON_ERROR));
        return [$quote, $calc, $digest];
    }

    private function state(CleaningBookingSession $visit, object $quote, array $calc, string $digest, ?int $exclude = null): array
    {
        $rows = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
        $collection = 0;
        $funded = 0;
        $collectionReference = null;
        $payouts = [];
        $pending = [];
        $refunds = 0;
        foreach ($rows as $row) {
            if ($exclude === (int) $row->id) {
                continue;
            }
            if ($row->quote_id !== $quote->id || ! hash_equals($row->calculation_digest, $digest)
                || $row->currency !== 'SYP' || ! in_array($row->status, ['pending', 'posted'], true)) {
                throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
            }
            if ($row->kind === 'collection') {
                if ($row->collection_reference !== null || $row->beneficiary_key !== 'treasury' || $collectionReference !== null) {
                    throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
                }
                if ($row->status === 'posted') {
                    $collectionReference = $row->reference;
                }
            } elseif ($row->kind === 'funding_topup') {
                if ($row->collection_reference !== null || $row->beneficiary_key !== 'treasury') {
                    throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
                }
            } elseif ($collectionReference === null || $row->collection_reference !== $collectionReference) {
                throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
            }
            $key = $row->kind.':'.$row->beneficiary_key;
            $amount = (int) bcmul((string) $row->amount, '100', 0);
            if ($row->status === 'pending') {
                $pending[$key] = ($pending[$key] ?? 0) + $amount;
                continue;
            }
            if ($row->verified_by === null || $row->verified_at === null || (int) $row->verified_by === (int) $row->recorded_by) {
                throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
            }
            match ($row->kind) {
                'collection' => $collection += $amount,
                'funding_topup' => $funded += $amount,
                'payout' => $payouts[$row->beneficiary_key] = ($payouts[$row->beneficiary_key] ?? 0) + $amount,
                'fee_refund' => $refunds += $amount,
                default => throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED'),
            };
        }
        $expected = [];
        foreach ($calc['workerPayables'] as $p) {
            $expected['worker:'.$p['workerId']] = CleaningV3CashSettlementBasis::cents($p['grossPayableSyp']);
        }
        foreach ($calc['supervisorPayables'] as $key => $amount) {
            if ($amount !== '0.00') {
                $expected[$key] = CleaningV3CashSettlementBasis::cents($amount);
            }
        }
        $due = CleaningV3CashSettlementBasis::cents($calc['totalDueSyp']);
        $fee = CleaningV3CashSettlementBasis::cents($calc['additionalWorkerFeeSyp']);
        $gap = CleaningV3CashSettlementBasis::cents($calc['unfundedSyp']);
        if ($collection > $due || $funded > $gap || $refunds > $fee || $refunds > $collection
            || array_diff_key($payouts, $expected) !== []
            || array_sum($payouts) > max(0, $collection - $fee) + $funded) {
            throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
        }
        foreach ($payouts as $key => $amount) {
            if ($amount > $expected[$key]) {
                throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
            }
        }
        $all = $collection === $due && $gap === $funded && array_sum($payouts) === array_sum($expected) && $pending === [];
        if ($visit->payment_status === 'settled' && ! $all && $exclude === null
            && ($collection !== $due || array_sum($payouts) !== array_sum($expected) || $gap !== $funded)) {
            throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
        }
        return [
            ...$calc, 'visitId' => (int) $visit->id, 'quoteId' => $quote->id,
            'calculationDigest' => $digest, 'collectionReference' => $collectionReference,
            'collectedCents' => $collection, 'refundedFeeCents' => $refunds,
            'verifiedFundingTopUpCents' => $funded,
            'remainingFundingGapSyp' => CleaningV3CashSettlementBasis::money($gap - $funded),
            'expectedPayoutsCents' => $expected, 'postedPayoutsCents' => $payouts, 'pendingCents' => $pending,
            'allPayablesPosted' => $all, 'paymentStatus' => $visit->payment_status,
            'movements' => $rows->map(static fn ($row) => [
                'reference' => $row->reference, 'kind' => $row->kind, 'beneficiary' => $row->beneficiary_key,
                'amount' => bcadd((string) $row->amount, '0', 2), 'status' => $row->status,
                'evidence' => $row->evidence, 'recordedBy' => (int) $row->recorded_by, 'verifiedBy' => $row->verified_by,
            ])->all(),
        ];
    }

    private function assertMovement(array $state, string $kind, string $beneficiary, string $amount): void
    {
        $cents = CleaningV3CashSettlementBasis::cents($amount);
        $pending = $state['pendingCents'][$kind.':'.$beneficiary] ?? 0;
        $due = CleaningV3CashSettlementBasis::cents($state['totalDueSyp']);
        $fee = CleaningV3CashSettlementBasis::cents($state['additionalWorkerFeeSyp']);
        $valid = match ($kind) {
            'collection' => $beneficiary === 'treasury' && $state['collectedCents'] === 0
                && $pending === 0 && $cents === $due,
            'funding_topup' => $beneficiary === 'treasury' && $state['collectedCents'] === $due
                && $state['verifiedFundingTopUpCents'] === 0 && $pending === 0
                && $cents === CleaningV3CashSettlementBasis::cents($state['unfundedSyp']),
            'payout' => $state['collectedCents'] === $due && $state['remainingFundingGapSyp'] === '0.00'
                && isset($state['expectedPayoutsCents'][$beneficiary])
                && ($state['postedPayoutsCents'][$beneficiary] ?? 0) === 0 && $pending === 0
                && $cents === $state['expectedPayoutsCents'][$beneficiary],
            'fee_refund' => $beneficiary === 'customer' && $state['collectedCents'] === $due
                && $state['paymentStatus'] === 'settled'
                && $cents + $pending + $state['refundedFeeCents'] <= $fee,
            default => false,
        };
        if ($cents <= 0 || ! $valid) {
            throw new DomainException('V3_CASH_MOVEMENT_NOT_ALLOWED');
        }
    }
}
