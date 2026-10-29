<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Transactionally assesses a prospective reservation using the same row-lock
 * order a future writer must use. No write or positive admission is possible
 * until an independent provider clearing adapter is implemented and trusted.
 */
final class CleaningV2FundingReservationAdmissionService
{
    public function __construct(private readonly CleaningV2VisitApprovalProofService $quoteProof) {}

    /** @return array{status:string,reasonCodes:list<string>,canReserve:bool,availableRecordedAmount:?string,requestedAmount:?string,customerQuoteVerified:bool} */
    public function assess(CleaningBookingSession $visit, int $sourceId, string $requestedAmount): array
    {
        return DB::transaction(function () use ($visit, $sourceId, $requestedAmount): array {
            $reasons = ['independent_clearing_integration_missing'];
            // All writers must obtain the visit lock before the source lock.
            // The source row then serializes allocations across different
            // visits. Both locks remain held through the entire assessment.
            $session = CleaningBookingSession::query()->whereKey($visit->id)
                ->lockForUpdate()->first();
            $source = DB::table('cleaning_platform_funding_sources')
                ->where('id', $sourceId)->lockForUpdate()->first();
            $amount = $this->cents($requestedAmount);
            if ($session === null || $session->isTerminal()) {
                $reasons[] = 'visit_unavailable';
            }
            if ($amount === null || $amount <= 0) {
                $reasons[] = 'requested_reservation_invalid';
            }
            if ($source === null) {
                $reasons[] = 'funding_source_missing';
            }

            $quoteVerified = false;
            if ($session !== null) {
                $booking = CleaningBooking::query()->whereKey($session->cleaning_booking_id)->first();
                if ($booking !== null && $booking->isV2OperationalGroup()) {
                    $quoteVerified = $this->quoteProof->verify($booking, $session)['valid'];
                }
                if (! $quoteVerified) {
                    $reasons[] = 'approved_customer_quote_unproven';
                }
                // Include malformed legacy rows with active_slot=NULL and
                // released_at=NULL: they are unclosed, not spendable capacity.
                if (DB::table('cleaning_visit_funding_reservations')
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereNull('released_at')->exists()) {
                    $reasons[] = 'visit_already_has_unreleased_reservation';
                }
            }

            $remaining = null;
            if ($source !== null) {
                $gross = $this->cents((string) $source->gross_amount);
                if ($source->owner_scope !== 'cleaning_platform') {
                    $reasons[] = 'funding_source_wrong_owner';
                }
                if ($source->verification_state !== 'cleared' || $source->cleared_at === null
                    || $source->provider_verified_at === null || $source->provider_evidence_digest === null) {
                    $reasons[] = 'funding_source_not_cleared';
                }
                if ($source->reversed_at !== null) {
                    $reasons[] = 'funding_source_reversed';
                }
                if ($session !== null && (string) $source->currency !== mb_strtoupper(mb_trim((string)
                    ($session->pricing_snapshot['currency'] ?? config('app.currency', 'SYP'))))) {
                    $reasons[] = 'funding_currency_mismatch';
                }

                $total = 0;
                foreach (DB::table('cleaning_visit_funding_reservations')
                    ->where('cleaning_platform_funding_source_id', $sourceId)
                    ->whereNull('released_at')->get(['reserved_amount']) as $reservation) {
                    $reserved = $this->cents((string) $reservation->reserved_amount);
                    if ($reserved === null || $reserved <= 0) {
                        $reasons[] = 'existing_reservation_invalid';
                    } else {
                        $total += $reserved;
                    }
                }
                if ($gross === null || $gross <= 0 || $total > $gross) {
                    $reasons[] = 'funding_source_overallocated';
                } else {
                    $remaining = $gross - $total;
                    if ($amount !== null && $amount > $remaining) {
                        $reasons[] = 'insufficient_unreserved_source_balance';
                    }
                }
            }

            // This is only a recorded gross-minus-reservations diagnostic;
            // never imply it represents bank-cleared, spendable platform cash.
            return [
                'status' => 'blocked',
                'reasonCodes' => array_values(array_unique($reasons)),
                'canReserve' => false,
                'availableRecordedAmount' => $remaining === null ? null : $this->format($remaining),
                'requestedAmount' => $amount === null ? null : $this->format($amount),
                'customerQuoteVerified' => $quoteVerified,
            ];
        });
    }

    private function cents(string $value): ?int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (mb_strlen($whole) > 13) {
            return null;
        }

        return (int) $whole * 100 + (int) mb_str_pad($fraction, 2, '0');
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
