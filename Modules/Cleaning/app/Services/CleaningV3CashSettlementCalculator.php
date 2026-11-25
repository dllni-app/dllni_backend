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
        if ($attendance === [] || count($attendance) > 100
            || count(array_unique(array_column($attendance, 'workerId'))) !== count($attendance)) {
            throw new DomainException('V3_SETTLEMENT_ROSTER_INCOMPLETE');
        }
        $attendanceSeconds = 0;
        foreach ($attendance as $entry) {
            if (! is_int($entry['workerId'] ?? null) || $entry['workerId'] < 1
                || ! is_int($entry['activeSeconds'] ?? null) || $entry['activeSeconds'] < 1
                || $entry['activeSeconds'] > $activeSeconds) {
                throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
            }
            $attendanceSeconds += $entry['activeSeconds'];
        }
        $seatSeconds = $workers * $activeSeconds;
        if ($attendanceSeconds > $seatSeconds) {
            throw new DomainException('V3_SETTLEMENT_ATTENDANCE_REVIEW_REQUIRED');
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
        $labor = $base + $addons - $material;
        // Legacy commission is a separate worker debt, not a second charge
        // to the customer or an increase based on the extra-worker surcharge.
        $commission = CleaningV3CashSettlementBasis::commissionCents($base + $addons, $basis['commissionType'], $cents($basis['commission']));
        // New signed quotes cap the company-funded discount at the final
        // commission too, including shortened time-based services.
        if (($basis['couponCommissionCapped'] ?? false) === true) {
            $discount = min($discount, $commission);
        }
        $total = $eligible + $travel + $fee - $discount;
        $supervised = $visit['billingMode'] === 'task' && $workers > 1;
        if ($supervised && ($supervisorActiveSeconds === null || $supervisorActiveSeconds < 1 || $supervisorActiveSeconds > $activeSeconds)) {
            throw new DomainException('V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
        }
        $supervisor = $supervised
            ? (int) bcdiv(bcadd(bcmul((string) $cents($basis['supervisorHourlyCompensation']), (string) $supervisorActiveSeconds, 0), '1800', 0), '3600', 0)
            : 0;
        $entries = [];
        usort($attendance, static fn ($a, $b) => $a['workerId'] <=> $b['workerId']);
        // Fixed task labor is a fixed pool distributed by actual verified
        // attendance. Event labor only pays the worked share of priced seats.
        $denominator = $visit['billingMode'] === 'task' ? $attendanceSeconds : $seatSeconds;
        $cursor = 0;
        $paidLabor = 0;
        $paidCommission = 0;
        $paidTravel = 0;
        $fraction = static fn (int $amount, int $seconds): int => (int) bcdiv(bcmul((string) $amount, (string) $seconds, 0), (string) $denominator, 0);
        foreach ($attendance as $i => $entry) {
            $next = $cursor + $entry['activeSeconds'];
            $share = $fraction($labor, $next) - $fraction($labor, $cursor);
            // Travel is fixed per attending worker. Replacement travel above
            // the quoted budget is a platform liability, not a customer surcharge.
            $travelShare = intdiv($travel * ($i + 1), $workers) - intdiv($travel * $i, $workers);
            $commissionShare = $fraction($commission, $next) - $fraction($commission, $cursor);
            $cursor = $next;
            $paidLabor += $share;
            $paidTravel += $travelShare;
            $paidCommission += $commissionShare;
            $entries[] = [
                'workerId' => $entry['workerId'], 'activeSeconds' => $entry['activeSeconds'],
                'serviceShareSyp' => $money($share), 'travelSyp' => $money($travelShare),
                'grossPayableSyp' => $money($share + $travelShare),
                'commissionReceivableSyp' => $money($commissionShare),
            ];
        }
        $obligations = $paidLabor + $paidTravel + $supervisor;
        // Do not finance ordinary worker liabilities with the protected fee,
        // a coupon promise, uncollected money, or worker deposits.
        $ordinary = $total - $fee;
        return [
            'occurrenceKey' => $occurrence, 'activeSeconds' => $activeSeconds, 'billableMinutes' => $minutes,
            'baseAmountSyp' => $money($base), 'discountAmountSyp' => $money($discount),
            'additionalWorkerFeeSyp' => $money($fee), 'totalDueSyp' => $money($total),
            'workerPayables' => $entries, 'supervisorRequired' => $supervised,
            'supervisorPayableSyp' => $money($supervisor), 'supervisorActiveSeconds' => $supervised ? $supervisorActiveSeconds : 0, 'commissionReceivableSyp' => $money($paidCommission),
            'totalObligationsSyp' => $money($obligations),
            'ordinaryCashRequiredSyp' => $money($obligations),
            'unfundedSyp' => $money(max(0, $obligations - $ordinary)),
            'canSettle' => false,
        ];
    }
}
