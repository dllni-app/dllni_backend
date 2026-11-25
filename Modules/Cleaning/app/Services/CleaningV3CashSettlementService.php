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
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

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
            return $this->state($visit, $quote, $calc, $digest);
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
                'reference' => $reference, 'collection_reference' => $kind === 'collection' ? null : $state['collectionReference'],
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
                        ->where('status', 'completed')->firstOrFail();
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
        $quotes = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)
            ->where('contract_version', 'v3')->lockForUpdate()->get();
        if ($quotes->count() !== 1) {
            throw new DomainException('V3_SETTLEMENT_QUOTE_LINK_REQUIRED');
        }
        $quote = $quotes->first();
        $catalog = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->first();
        $envelope = json_decode((string) $quote->snapshot, true);
        if ($catalog === null || $catalog->status !== 'published' || $catalog->published_at === null
            || (int) $quote->catalog_version_id !== (int) $catalog->id || (int) $quote->customer_id !== (int) $booking->customer_id
            || ! is_array($envelope) || ($envelope['quoteId'] ?? null) !== $quote->id
            || ! is_string($envelope['signature'] ?? null) || ! hash_equals((string) $quote->signature, $envelope['signature'])
            || ! hash_equals((string) $catalog->published_digest, app(CleaningV3CatalogReviewManifestService::class)->fingerprint($catalog))) {
            throw new DomainException('V3_SETTLEMENT_QUOTE_LINK_REQUIRED');
        }
        // Verify the historic quote at the original booking creation, not
        // against today's catalog or current 15-minute quote lifetime.
        $acceptedAt = CarbonImmutable::instance($booking->created_at)->utc();
        $snapshot = app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, (int) $booking->customer_id,
            $catalog->version_key, $catalog->published_digest, $acceptedAt);
        $row = collect($snapshot['commercialReview']['visits'] ?? [])->firstWhere('occurrenceKey', $visit->occurrence_key);
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
        $assignments = $visit->workerAssignments()->where('status', 'completed')->orderBy('worker_id')->lockForUpdate()->get();
        foreach ($assignments as $assignment) {
            if ($assignment->work_started_at === null || $assignment->work_finished_at === null
                || $assignment->released_at !== null) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
            }
            $start = CarbonImmutable::instance($assignment->work_started_at)->utc();
            $end = CarbonImmutable::instance($assignment->work_finished_at)->utc();
            $seconds = 0;
            foreach ($intervals as $interval) {
                $a = CarbonImmutable::parse($interval->started_at, 'UTC')->max($start);
                $b = CarbonImmutable::parse($interval->ended_at, 'UTC')->min($end);
                if ($b > $a) {
                    $seconds += (int) $a->diffInSeconds($b, false);
                }
            }
            $attendance[] = ['workerId' => (int) $assignment->worker_id, 'activeSeconds' => $seconds];
        }
        $mode = $snapshot['settlementBasis']['visits'][$visit->occurrence_key]['billingMode'] ?? null;
        $supervised = $mode === 'task' && $row['pricedWorkers'] > 1;
        if ($supervised !== ($visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)) {
            throw new DomainException('V3_SETTLEMENT_SUPERVISION_MISMATCH');
        }
        $proof = $supervised ? app(CleaningV3SupervisorAttendanceProofService::class)->verified($visit) : null;
        $calc = app(CleaningV3CashSettlementCalculator::class)->calculate(
            $snapshot, $visit->occurrence_key, $clock['activeSeconds'], $attendance, $proof['active_seconds'] ?? null,
        );
        $calc['supervisorUserId'] = $proof['supervisor_user_id'] ?? null;
        $calc['supervisorProofReference'] = $proof['proofReference'] ?? null;
        $digest = hash('sha256', json_encode([$quote->id, $visit->id, $snapshot['pricingRevision'], $clock['revision'], $calc], JSON_THROW_ON_ERROR));
        return [$quote, $calc, $digest];
    }

    private function state(CleaningBookingSession $visit, object $quote, array $calc, string $digest, ?int $exclude = null): array
    {
        $rows = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
        $collection = 0;
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
                'payout' => $payouts[$row->beneficiary_key] = ($payouts[$row->beneficiary_key] ?? 0) + $amount,
                'fee_refund' => $refunds += $amount,
                default => throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED'),
            };
        }
        $expected = [];
        foreach ($calc['workerPayables'] as $p) {
            $expected['worker:'.$p['workerId']] = CleaningV3CashSettlementBasis::cents($p['grossPayableSyp']);
        }
        if ($calc['supervisorRequired'] && $calc['supervisorPayableSyp'] !== '0.00') {
            $expected['supervisor:'.$calc['supervisorUserId']] = CleaningV3CashSettlementBasis::cents($calc['supervisorPayableSyp']);
        }
        $due = CleaningV3CashSettlementBasis::cents($calc['totalDueSyp']);
        $fee = CleaningV3CashSettlementBasis::cents($calc['additionalWorkerFeeSyp']);
        $gap = CleaningV3CashSettlementBasis::cents($calc['unfundedSyp']);
        if ($collection > $due || $refunds > $fee || $refunds > $collection
            || array_diff_key($payouts, $expected) !== []
            || array_sum($payouts) > max(0, $collection - $fee)) {
            throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
        }
        foreach ($payouts as $key => $amount) {
            if ($amount > $expected[$key]) {
                throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
            }
        }
        $all = $collection === $due && $gap === 0 && array_sum($payouts) === array_sum($expected) && $pending === [];
        if ($visit->payment_status === 'settled' && ! $all && $exclude === null
            && ($collection !== $due || array_sum($payouts) !== array_sum($expected) || $gap !== 0)) {
            throw new DomainException('V3_CASH_LEDGER_RECONCILIATION_REQUIRED');
        }
        return [
            ...$calc, 'visitId' => (int) $visit->id, 'quoteId' => $quote->id,
            'calculationDigest' => $digest, 'collectionReference' => $collectionReference,
            'collectedCents' => $collection, 'refundedFeeCents' => $refunds,
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
            'payout' => $state['collectedCents'] === $due && $state['unfundedSyp'] === '0.00'
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
