<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * A signed external reservation, never an internal balance declaration.
 * All callers lock booking then visit first, then this service locks credit.
 */
final class CleaningV3VerifiedTreasuryReservationService
{
    public function reserve(CleaningBooking $booking, CleaningBookingSession $visit, string $quoteId,
        int $revision, string $digest, array $obligations, array $envelope, int $actorId): void
    {
        $this->assertTransaction($booking, $visit);
        $p = app(CleaningV3TreasuryAllocationVerifier::class)->verify($envelope);
        $this->assertMatches($p, $booking, $visit, $quoteId, $revision, $digest, $obligations);
        if (! \App\Models\User::query()->whereKey($actorId)->where('is_active', true)->exists()) {
            throw new DomainException('V3_TREASURY_IMPORT_ACTOR_REQUIRED');
        }
        $creditDigest = $this->creditDigest($p);
        DB::table('cleaning_platform_funding_sources')->insertOrIgnore([
            'provider_key' => $p['provider'], 'external_credit_id' => $p['creditId'], 'owner_scope' => 'cleaning_platform',
            'currency' => 'SYP', 'gross_amount' => $p['grossCreditSyp'], 'verification_state' => 'cleared',
            'provider_evidence_digest' => $creditDigest, 'provider_verified_at' => now('UTC'), 'cleared_at' => now('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $source = DB::table('cleaning_platform_funding_sources')->where('provider_key', $p['provider'])
            ->where('external_credit_id', $p['creditId'])->lockForUpdate()->first();
        $this->assertSource($source, $p);
        $active = DB::table('cleaning_visit_funding_reservations')->where('active_visit_id', $visit->id)->lockForUpdate()->first();
        $used = DB::table('cleaning_visit_funding_reservations')->where('reservation_key', $p['allocationId'])->lockForUpdate()->first();
        $replace = false;
        if ($active !== null) {
            if ((int) $active->funding_source_id !== (int) $source->id
                || (int) $active->cleaning_booking_session_id !== (int) $visit->id
                || $active->released_at !== null || $active->reservation_state !== 'approved') {
                throw new DomainException('V3_TREASURY_EXISTING_RESERVATION_RECONCILIATION_REQUIRED');
            }
            $stored = json_decode((string) $active->liability_snapshot, true);
            $old = $stored['proof']['payload'] ?? [];
            $refresh = $used !== null && (int) $used->id === (int) $active->id
                && hash_equals($active->quote_evidence_digest, $digest)
                && bccomp((string) $active->reserved_amount, $p['reservedSyp'], 2) === 0
                && ($old['supersedesAllocationId'] ?? null) === $p['supersedesAllocationId'];
            // Only an independently signed replacement may release the old
            // reserved slot. An extension never manufactures more credit.
            $replace = $used === null && $p['supersedesAllocationId'] === $active->reservation_key
                && ($old['quoteId'] ?? null) === $quoteId
                && ($old['pricingRevision'] ?? 0) + 1 === $revision
                && bccomp($p['reservedSyp'], (string) $active->reserved_amount, 2) >= 0;
            if (! $refresh && ! $replace) {
                throw new DomainException('V3_TREASURY_EXISTING_RESERVATION_RECONCILIATION_REQUIRED');
            }
        } elseif ($used !== null || $p['supersedesAllocationId'] !== null) {
            throw new DomainException('V3_TREASURY_ALLOCATION_ALREADY_USED');
        }
        $sum = $this->allocated((int) $source->id, $active === null ? null : (int) $active->id);
        if (bccomp(bcadd($sum, $p['reservedSyp'], 2), $p['grossCreditSyp'], 2) > 0) {
            throw new DomainException('V3_TREASURY_INSUFFICIENT_CREDIT');
        }
        $values = [
            'funding_source_id' => $source->id, 'cleaning_booking_session_id' => $visit->id, 'active_visit_id' => $visit->id,
            'quote_evidence_digest' => $digest, 'currency' => 'SYP', 'reserved_amount' => $p['reservedSyp'],
            'worker_obligation_amount' => $obligations['workerObligationSyp'],
            'supervisor_obligation_amount' => $obligations['supervisorObligationSyp'],
            'liability_snapshot' => json_encode(['proof' => $envelope, 'obligations' => $obligations], JSON_THROW_ON_ERROR),
            'reservation_state' => 'approved', 'approved_by' => $actorId, 'approved_at' => now('UTC'), 'updated_at' => now(),
        ];
        if ($replace) {
            DB::table('cleaning_visit_funding_reservations')->where('id', $active->id)->update([
                'active_visit_id' => null, 'reservation_state' => 'released', 'released_at' => now('UTC'),
                'release_reason' => 'Independent treasury allocation superseded: '.$p['allocationId'], 'updated_at' => now(),
            ]);
        }
        if ($active === null || $replace) {
            DB::table('cleaning_visit_funding_reservations')->insert([
                ...$values, 'reservation_key' => $p['allocationId'], 'created_at' => now(),
            ]);
        } else {
            DB::table('cleaning_visit_funding_reservations')->where('id', $active->id)->update($values);
        }
    }

    public function assertVerified(CleaningBooking $booking, CleaningBookingSession $visit, string $quoteId,
        int $revision, string $digest, array $obligations): void
    {
        $this->assertTransaction($booking, $visit);
        $row = DB::table('cleaning_visit_funding_reservations')->where('active_visit_id', $visit->id)->lockForUpdate()->first();
        if ($row === null || (int) $row->cleaning_booking_session_id !== (int) $visit->id || $row->reservation_state !== 'approved'
            || $row->released_at !== null || $row->approved_by === null || $row->approved_at === null || $row->currency !== 'SYP'
            || ! hash_equals($row->quote_evidence_digest, $digest)
            || bccomp((string) $row->worker_obligation_amount, $obligations['workerObligationSyp'], 2) !== 0
            || bccomp((string) $row->supervisor_obligation_amount, $obligations['supervisorObligationSyp'], 2) !== 0) {
            throw new DomainException('V3_VERIFIED_TREASURY_RESERVATION_REQUIRED');
        }
        $stored = json_decode((string) $row->liability_snapshot, true);
        if (! is_array($stored['proof'] ?? null) || ($stored['obligations'] ?? null) != $obligations) {
            throw new DomainException('V3_VERIFIED_TREASURY_RESERVATION_REQUIRED');
        }
        $p = app(CleaningV3TreasuryAllocationVerifier::class)->verify($stored['proof']);
        $this->assertMatches($p, $booking, $visit, $quoteId, $revision, $digest, $obligations);
        if ($row->reservation_key !== $p['allocationId'] || bccomp((string) $row->reserved_amount, $p['reservedSyp'], 2) !== 0) {
            throw new DomainException('V3_VERIFIED_TREASURY_RESERVATION_REQUIRED');
        }
        $source = DB::table('cleaning_platform_funding_sources')->where('id', $row->funding_source_id)->lockForUpdate()->first();
        $this->assertSource($source, $p);
        if (bccomp($this->allocated((int) $source->id, null), $p['grossCreditSyp'], 2) > 0) {
            throw new DomainException('V3_TREASURY_INSUFFICIENT_CREDIT');
        }
    }

    private function assertTransaction(CleaningBooking $booking, CleaningBookingSession $visit): void
    {
        if (DB::transactionLevel() < 1 || $booking->contract_version !== 'v3'
            || (int) $visit->cleaning_booking_id !== (int) $booking->id) {
            throw new DomainException('V3_TREASURY_TRANSACTION_REQUIRED');
        }
    }

    private function assertMatches(array $p, CleaningBooking $booking, CleaningBookingSession $visit, string $quoteId,
        int $revision, string $digest, array $obligations): void
    {
        if ($p['bookingId'] !== (int) $booking->id || $p['visitId'] !== (int) $visit->id || $p['quoteId'] !== $quoteId
            || $p['pricingRevision'] !== $revision || ! hash_equals($p['pricingDigest'], $digest)
            || bccomp($p['reservedSyp'], $obligations['requiredFundingSyp'], 2) < 0) {
            throw new DomainException('V3_TREASURY_ALLOCATION_PRICE_MISMATCH');
        }
    }

    private function creditDigest(array $p): string
    {
        return hash('sha256', json_encode([$p['provider'], $p['creditId'], $p['owner'], $p['currency'], $p['grossCreditSyp']], JSON_THROW_ON_ERROR));
    }

    private function assertSource(?object $source, array $p): void
    {
        if ($source === null || $source->provider_key !== $p['provider'] || $source->external_credit_id !== $p['creditId']
            || $source->owner_scope !== 'cleaning_platform' || $source->currency !== 'SYP' || $source->verification_state !== 'cleared'
            || $source->provider_verified_at === null || $source->cleared_at === null || $source->reversed_at !== null
            || ! hash_equals((string) $source->provider_evidence_digest, $this->creditDigest($p))
            || bccomp((string) $source->gross_amount, $p['grossCreditSyp'], 2) !== 0) {
            throw new DomainException('V3_TREASURY_CREDIT_RECONCILIATION_REQUIRED');
        }
    }

    private function allocated(int $sourceId, ?int $except): string
    {
        // The source lock serializes all writers. Never release an external
        // allocation just because local time passed or a customer cancelled.
        $rows = DB::table('cleaning_visit_funding_reservations')->where('funding_source_id', $sourceId)
            ->whereNull('released_at')->orderBy('id')->lockForUpdate()->get();
        $sum = '0.00';
        foreach ($rows as $r) {
            if ((int) $r->id === $except) {
                continue;
            }
            if ($r->currency !== 'SYP' || $r->active_visit_id === null || $r->reservation_state !== 'approved'
                || bccomp((string) $r->reserved_amount, '0', 2) <= 0) {
                throw new DomainException('V3_TREASURY_CREDIT_RECONCILIATION_REQUIRED');
            }
            $sum = bcadd($sum, (string) $r->reserved_amount, 2);
        }
        return $sum;
    }
}
