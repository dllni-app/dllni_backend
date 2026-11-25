<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Compute final cash due and gross obligations from a VERIFIED quote and
 * server-clock/attendance evidence. Not a cash collection or payout itself.
 */
final class CleaningV3CashSettlementCalculator
{
    public function calculate(array $snapshot, string $occurrence, int $activeSeconds, array $attendance, ?int $supervisorActiveSeconds = null): array
    {
        $policy = app(CleaningV3CashSettlementBasis::class);
        $policy->assertValid($snapshot);
        $basis = $snapshot['settlementBasis'];
        $visit = $basis['visits'][$occurrence] ?? null;
        $rows = array_values(array_filter($snapshot['commercialReview']['visits'], static fn ($row) => $row['occurrenceKey'] === $occurrence));
        if ($visit === null || count($rows) !== 1 || $activeSeconds <= 0
            || $activeSeconds > $visit['plannedMinutes'] * 60) {
            throw new DomainException('V3_SETTLEMENT_REQUIRES_APPROVED_TIME');
        }
        $row = $rows[0];
        $workers = $row['pricedWorkers'];
        $money = CleaningV3CashSettlementBasis::money(...);
        $cents = CleaningV3CashSettlementBasis::cents(...);
        if (count($attendance) !== $workers || count(array_unique(array_column($attendance, 'workerId'))) !== $workers) {
            throw new DomainException('V3_SETTLEMENT_ROSTER_INCOMPLETE');
        }
        // An incomplete/replacement attendance history needs a distinct
        // approved entitlement policy. Never redistribute another worker's pay.
        foreach ($attendance as $entry) {
            if (! is_int($entry['workerId'] ?? null) || $entry['workerId'] < 1
                || ($entry['activeSeconds'] ?? null) !== $activeSeconds) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
            }
        }
        $base = $cents($row['baseAmountSyp']);
        $minutes = intdiv($activeSeconds + 59, 60);
        if ($visit['billingMode'] === 'event') {
            $minutes = app(CleaningV3ActiveTimeBillingCalculator::class)->calculate(
                $activeSeconds, 60, 30, $visit['plannedMinutes'],
            )['billableMinutes'];
            if ($minutes !== $visit['plannedMinutes']) {
                $base = min($base, $policy->eventAmount($visit, $minutes, $workers));
            }
        }
        $addons = $cents($row['addonsAmountSyp']);
        $material = $cents($visit['materialAmountSyp']);
        $equipment = $cents($row['equipmentAmountSyp']);
        $supervisorFee = $cents($row['supervisorAmountSyp']);
        $topUp = $cents($row['minimumTopUpSyp']);
        $travel = $cents($row['travelAmountSyp']);
        $fee = $cents($row['additionalWorkerFeeSyp']);
        $eligible = $base + $addons + $equipment + $supervisorFee + $topUp;
        $discount = min($eligible, $cents($row['discountAmountSyp']));
        $total = $eligible + $travel + $fee - $discount;
        $labor = $base + $addons - $material;
        // Legacy commission is a separate worker debt, not a second charge
        // to the customer or an increase based on the extra-worker surcharge.
        $commissionRate = $cents($basis['commission']);
        $commission = $basis['commissionType'] === 'fixed' ? $commissionRate
            : (int) bcdiv(bcadd(bcmul((string) ($base + $addons), (string) $commissionRate, 0), '999999', 0), '1000000', 0) * 100;
        $supervised = $visit['billingMode'] === 'task' && $workers > 1;
        if ($supervised && ($supervisorActiveSeconds === null || $supervisorActiveSeconds < 1 || $supervisorActiveSeconds > $activeSeconds)) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
        }
        $supervisor = $supervised
            ? (int) bcdiv(bcadd(bcmul((string) $cents($basis['supervisorHourlyCompensation']), (string) $supervisorActiveSeconds, 0), '1800', 0), '3600', 0)
            : 0;
        $entries = [];
        usort($attendance, static fn ($a, $b) => $a['workerId'] <=> $b['workerId']);
        foreach ($attendance as $i => $entry) {
            $share = intdiv($labor * ($i + 1), $workers) - intdiv($labor * $i, $workers);
            $travelShare = intdiv($travel * ($i + 1), $workers) - intdiv($travel * $i, $workers);
            $commissionShare = intdiv($commission * ($i + 1), $workers) - intdiv($commission * $i, $workers);
            $entries[] = [
                'workerId' => $entry['workerId'], 'activeSeconds' => $activeSeconds,
                'serviceShareSyp' => $money($share), 'travelSyp' => $money($travelShare),
                'grossPayableSyp' => $money($share + $travelShare),
                'commissionReceivableSyp' => $money($commissionShare),
            ];
        }
        $obligations = $labor + $travel + $supervisor;
        // Do not finance ordinary worker liabilities with the protected fee,
        // a coupon promise, uncollected money, or worker deposits.
        $ordinary = $total - $fee;
        return [
            'occurrenceKey' => $occurrence, 'activeSeconds' => $activeSeconds, 'billableMinutes' => $minutes,
            'baseAmountSyp' => $money($base), 'discountAmountSyp' => $money($discount),
            'additionalWorkerFeeSyp' => $money($fee), 'totalDueSyp' => $money($total),
            'workerPayables' => $entries, 'supervisorRequired' => $supervised,
            'supervisorPayableSyp' => $money($supervisor), 'supervisorActiveSeconds' => $supervised ? $supervisorActiveSeconds : 0, 'commissionReceivableSyp' => $money($commission),
            'totalObligationsSyp' => $money($obligations),
            'ordinaryCashRequiredSyp' => $money($obligations),
            'unfundedSyp' => $money(max(0, $obligations - $ordinary)),
            'canSettle' => false,
        ];
    }
}
