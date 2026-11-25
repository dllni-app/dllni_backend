<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/** A visit-specific revision; never changes the frozen crew, discount or fee. */
final class CleaningV3LiveExtensionPricing
{
    public function revise(array $verifiedSnapshot, string $occurrence, int $additionalMinutes): array
    {
        $basis = app(CleaningV3CashSettlementBasis::class);
        $basis->assertValid($verifiedSnapshot);
        $row = collect($verifiedSnapshot['commercialReview']['visits'])->firstWhere('occurrenceKey', $occurrence);
        $visit = $verifiedSnapshot['settlementBasis']['visits'][$occurrence] ?? null;
        if ($row === null || $visit === null || $additionalMinutes < 30 || $additionalMinutes % 30 !== 0
            || $visit['plannedMinutes'] + $additionalMinutes > 480) {
            throw new DomainException('V3_EXTENSION_DURATION_INVALID');
        }
        $oldMinutes = $visit['plannedMinutes'];
        $visit['plannedMinutes'] += $additionalMinutes;
        $oldTotal = $row['totalAmountSyp'];
        $oldBase = CleaningV3CashSettlementBasis::cents($row['baseAmountSyp']);
        // A fixed task does not become a second chargeable task just because
        // it takes longer. A time-based event uses the original frozen rate.
        $newBase = $visit['billingMode'] === 'event'
            ? $basis->eventAmount($visit, $visit['plannedMinutes'], $row['pricedWorkers'])
            : $oldBase;
        $increment = $newBase - $oldBase;
        if ($increment < 0) {
            throw new DomainException('V3_EXTENSION_PRICE_INVALID');
        }
        $money = CleaningV3CashSettlementBasis::money(...);
        $cents = CleaningV3CashSettlementBasis::cents(...);
        $row['baseAmountSyp'] = $money($newBase);
        $row['eligibleBeforeDiscountSyp'] = $money($cents($row['eligibleBeforeDiscountSyp']) + $increment);
        $row['totalAmountSyp'] = $money($cents($oldTotal) + $increment);
        $supervised = $visit['billingMode'] === 'task' && $row['pricedWorkers'] > 1;
        $supervisor = $supervised
            ? (int) bcdiv(bcadd(bcmul((string) $cents($verifiedSnapshot['settlementBasis']['supervisorHourlyCompensation']),
                (string) $visit['plannedMinutes'], 0), '59', 0), '60', 0)
            : 0;
        if (($visit['commissionAdded'] ?? false) === true) {
            $supervisor = $cents($visit['supervisorVisitCompensation']);
        }
        $worker = $newBase + $cents($row['addonsAmountSyp']) - $cents($visit['materialAmountSyp']) + $cents($row['travelAmountSyp']);
        return [
            'oldMinutes' => $oldMinutes, 'newMinutes' => $visit['plannedMinutes'],
            'additionalMinutes' => $additionalMinutes, 'oldTotalSyp' => $oldTotal,
            'newTotalSyp' => $row['totalAmountSyp'], 'additionalAmountSyp' => $money($increment),
            'additionalWorkerFeeSyp' => $row['additionalWorkerFeeSyp'],
            'discountAmountSyp' => $row['discountAmountSyp'],
            'workerObligationSyp' => $money($worker),
            'supervisorObligationSyp' => $money($supervisor),
            'requiredFundingSyp' => $money($worker + $supervisor),
            'row' => $row, 'basis' => $visit,
        ];
    }

    /** A stable, canonical hash binds external allocations to this exact revision. */
    public function digest(string $quoteId, int $bookingId, int $visitId, int $revision, array $row, array $basis): string
    {
        ksort($row);
        ksort($basis);
        return hash('sha256', json_encode(['cleaning-v3-visit-price-v1', $quoteId, $bookingId, $visitId, $revision, $row, $basis], JSON_THROW_ON_ERROR));
    }
}
