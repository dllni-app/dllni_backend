<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Load the original customer-approved signed commercial price under a visit lock. */
final class CleaningV3ConfirmedVisitPrice
{
    public function load(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        if (DB::transactionLevel() < 1 || $booking->contract_version !== 'v3'
            || (int) $visit->cleaning_booking_id !== (int) $booking->id) {
            throw new DomainException('V3_VISIT_PRICE_TRANSACTION_REQUIRED');
        }
        [$quote, $snapshot, $sequence] = app(CleaningV3AcceptedQuoteResolver::class)
            ->load($booking, 'V3_CONFIRMED_QUOTE_REQUIRED');
        if ($sequence !== null) {
            app(CleaningV3DailyQuoteScope::class)->assertExecution($snapshot, $sequence, $visit);
        }
        $key = $visit->occurrence_key;
        $row = collect($snapshot['commercialReview']['visits'] ?? [])->firstWhere('occurrenceKey', $key);
        $basis = $snapshot['settlementBasis']['visits'][$key] ?? null;
        if ($row === null || $basis === null || $row['appointmentKey'] !== $visit->appointment_key
            || $row['pricedWorkers'] !== $visit->requiredWorkerCount()) {
            throw new DomainException('V3_CONFIRMED_VISIT_PRICE_MISMATCH');
        }
        $revision = $snapshot['pricingRevision'];
        return [$quote, $snapshot, $row, $basis, $revision];
    }

    public function assertPersisted(CleaningBookingSession $visit, array $row, array $basis, int $revision): void
    {
        if (! $visit->is_pricing_final || $revision !== (int) $visit->price_revision
            || bccomp($row['totalAmountSyp'], (string) $visit->getRawOriginal('total_price'), 2) !== 0
            || (int) round((float) $visit->duration_hours * 60) !== $basis['plannedMinutes']
            || bccomp((string) ($visit->getRawOriginal('extension_fee_total') ?? 0), '0', 2) !== 0) {
            throw new DomainException('V3_CONFIRMED_VISIT_PRICE_MISMATCH');
        }
    }

    public function obligations(array $snapshot, array $row, array $basis): array
    {
        $cents = CleaningV3CashSettlementBasis::cents(...);
        $money = CleaningV3CashSettlementBasis::money(...);
        $worker = $cents($row['baseAmountSyp']) + $cents($row['addonsAmountSyp'])
            - $cents($basis['materialAmountSyp']) + $cents($row['travelAmountSyp']);
        $supervisor = $basis['billingMode'] === 'task' && $row['pricedWorkers'] > 1
            ? (int) bcdiv(bcadd(bcmul((string) $cents($snapshot['settlementBasis']['supervisorHourlyCompensation']),
                (string) $basis['plannedMinutes'], 0), '59', 0), '60', 0) : 0;
        if (($basis['commissionAdded'] ?? false) === true) {
            $supervisor = $cents($basis['supervisorVisitCompensation']);
        }
        return ['workerObligationSyp' => $money($worker), 'supervisorObligationSyp' => $money($supervisor),
            'requiredFundingSyp' => $money($worker + $supervisor)];
    }
}
