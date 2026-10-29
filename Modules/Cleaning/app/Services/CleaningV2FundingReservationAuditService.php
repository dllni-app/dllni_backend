<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Read-only funding provenance audit. A stored status/approval/evidence hash
 * cannot replace an independent cleared-credit verification integration.
 * This service deliberately has NO write method or positive funding path.
 */
final class CleaningV2FundingReservationAuditService
{
    /** @return array{status:string,reasonCodes:list<string>,activeReservationCount:int,reservedAmount:?string,currency:string,fundingProven:bool} */
    public function inspect(CleaningBookingSession $session, ?string $trustedQuoteDigest = null): array
    {
        $currency = mb_strtoupper(mb_trim((string) ($session->pricing_snapshot['currency'] ?? config('app.currency', 'SYP'))));
        $reasons = ['independent_clearing_integration_missing'];
        if (! Schema::hasTable('cleaning_platform_funding_sources')
            || ! Schema::hasTable('cleaning_visit_funding_reservations')) {
            return $this->result([...$reasons, 'funding_registry_schema_missing'], 0, null, $currency);
        }

        $rows = DB::table('cleaning_visit_funding_reservations as reservation')
            ->leftJoin('cleaning_platform_funding_sources as source',
                'source.id', '=', 'reservation.cleaning_platform_funding_source_id')
            ->where('reservation.cleaning_booking_session_id', $session->id)
            ->whereNull('reservation.released_at')
            ->get([
                'reservation.cleaning_platform_funding_source_id as source_id',
                'reservation.reserved_amount', 'reservation.currency as reservation_currency',
                'reservation.quote_evidence_digest', 'reservation.worker_obligations_amount',
                'reservation.supervisor_obligation_amount', 'reservation.approved_by_user_id',
                'reservation.approved_at', 'reservation.active_slot',
                'source.id as existing_source_id', 'source.owner_scope',
                'source.currency as source_currency', 'source.gross_amount',
                'source.verification_state', 'source.provider_evidence_digest',
                'source.provider_verified_at', 'source.cleared_at', 'source.reversed_at',
            ]);

        if ($rows->isEmpty()) {
            return $this->result([...$reasons, 'funding_reservation_missing'], 0, null, $currency);
        }

        // An older or manually corrupted row may have released_at=NULL but
        // active_slot=NULL, outside the unique active-slot index. Keep this
        // independently visible instead of treating it as valid funding.
        if ($rows->count() > 1) {
            $reasons[] = 'multiple_active_visit_funding_reservations';
        }

        $reservedCents = 0;
        $sources = [];
        foreach ($rows as $row) {
            $amount = $this->cents($row->reserved_amount);
            $workers = $this->cents($row->worker_obligations_amount);
            $supervisor = $this->cents($row->supervisor_obligation_amount);
            if ($amount === null || $amount <= 0 || $workers === null || $workers < 0
                || $supervisor === null || $supervisor < 0) {
                $reasons[] = 'reservation_amount_invalid';
            } else {
                $reservedCents += $amount;
                if ($amount < $workers + $supervisor) {
                    $reasons[] = 'reservation_below_recorded_obligations';
                }
            }

            if ((string) $row->reservation_currency !== $currency
                || (string) $row->source_currency !== $currency) {
                $reasons[] = 'funding_currency_mismatch';
            }
            if (! is_string($trustedQuoteDigest)
                || ! preg_match('/^[a-f0-9]{64}$/D', $trustedQuoteDigest)
                || ! hash_equals($trustedQuoteDigest, (string) $row->quote_evidence_digest)) {
                $reasons[] = 'funding_quote_digest_unverified';
            }
            if ($row->approved_at === null || $row->approved_by_user_id === null
                || (int) $row->active_slot !== 1) {
                $reasons[] = 'funding_reservation_not_approved';
            }
            if ($row->existing_source_id === null || (string) $row->owner_scope !== 'cleaning_platform') {
                $reasons[] = 'funding_source_not_platform_owned';
            }
            if ($row->reversed_at !== null) {
                $reasons[] = 'funding_source_reversed';
            }
            if ($row->verification_state !== 'cleared' || $row->cleared_at === null
                || $row->provider_verified_at === null
                || ! preg_match('/^[a-f0-9]{64}$/iD', (string) $row->provider_evidence_digest)) {
                $reasons[] = 'funding_source_clearing_unverified';
            }
            $sources[(int) $row->source_id] = $row->gross_amount;
        }

        // A single source may be reserved for several visits. Verify the
        // aggregate, not just this visit's row, before trusting any balance.
        foreach ($sources as $sourceId => $gross) {
            $grossCents = $this->cents($gross);
            $active = DB::table('cleaning_visit_funding_reservations')
                ->where('cleaning_platform_funding_source_id', $sourceId)
                ->whereNull('released_at')->get(['reserved_amount']);
            $total = 0;
            foreach ($active as $reservation) {
                $value = $this->cents($reservation->reserved_amount);
                if ($value === null || $value <= 0) {
                    $reasons[] = 'reservation_amount_invalid';
                } else {
                    $total += $value;
                }
            }
            if ($grossCents === null || $grossCents <= 0 || $total > $grossCents) {
                $reasons[] = 'funding_source_overallocated';
            }
        }

        return $this->result($reasons, $rows->count(), $reservedCents, $currency);
    }

    /** @param list<string> $reasons */
    private function result(array $reasons, int $count, ?int $cents, string $currency): array
    {
        return [
            'status' => 'blocked',
            'reasonCodes' => array_values(array_unique($reasons)),
            'activeReservationCount' => $count,
            'reservedAmount' => $cents === null ? null : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
            'currency' => $currency,
            'fundingProven' => false,
        ];
    }

    private function cents(mixed $value): ?int
    {
        if (! is_numeric($value) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        if (mb_strlen($whole) > 13) {
            return null;
        }

        return (int) $whole * 100 + (int) mb_str_pad($fraction, 2, '0');
    }
}
