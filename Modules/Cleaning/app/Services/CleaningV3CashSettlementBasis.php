<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/** The frozen financial basis travels with the quote, never with client prices. */
final class CleaningV3CashSettlementBasis
{
    public function assertValid(array $snapshot): void
    {
        $basis = $snapshot['settlementBasis'] ?? null;
        if (! is_array($basis) || ($basis['version'] ?? null) !== 1
            || ! in_array($basis['commissionType'] ?? null, ['fixed', 'percent'], true)
            || ! is_array($basis['visits'] ?? null)
            || count($basis['visits']) !== count($snapshot['commercialReview']['visits'] ?? [])) {
            throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
        }
        $commission = self::cents($basis['commission'] ?? null);
        if ($basis['commissionType'] === 'percent' && $commission > 10000) {
            throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
        }
        self::cents($basis['supervisorHourlyCompensation'] ?? null);
        if (array_key_exists('couponCommissionCapped', $basis) && $basis['couponCommissionCapped'] !== true) {
            throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
        }
        foreach ($snapshot['commercialReview']['visits'] as $row) {
            if (($basis['couponCommissionCapped'] ?? false) === true
                && self::cents($row['discountAmountSyp']) > self::commissionCents(
                    self::cents($row['baseAmountSyp']) + self::cents($row['addonsAmountSyp']), $basis['commissionType'], $commission)) {
                throw new DomainException('V3_COUPON_EXCEEDS_COMMISSION');
            }
            $visit = $basis['visits'][$row['occurrenceKey']] ?? null;
            if (! is_array($visit) || ! in_array($visit['billingMode'] ?? null, ['task', 'event'], true)
                || ! is_int($visit['plannedMinutes'] ?? null) || $visit['plannedMinutes'] < 1 || $visit['plannedMinutes'] > 480
                || self::cents($visit['materialAmountSyp'] ?? null) > self::cents($row['addonsAmountSyp'])) {
                throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
            }
            if ($visit['billingMode'] === 'event') {
                if ($visit['plannedMinutes'] < 60 || $visit['plannedMinutes'] % 30 !== 0
                    || self::cents($visit['eventRateSyp'] ?? null) <= 0
                    || ! in_array($visit['eventRateMinutes'] ?? null, [30, 60], true)
                    || self::cents($row['addonsAmountSyp']) !== 0) {
                    throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
                }
                $scheduled = $this->eventAmount($visit, $visit['plannedMinutes'], $row['pricedWorkers']);
                // The published hourly tier uses cumulative cent rounding across
                // split visits, so a single segment may differ by one cent.
                if (abs($scheduled - self::cents($row['baseAmountSyp'])) > 1) {
                    throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
                }
            } elseif (($visit['eventRateSyp'] ?? null) !== null || ($visit['eventRateMinutes'] ?? null) !== null) {
                throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
            }
        }
    }

    public function eventAmount(array $visit, int $minutes, int $workers): int
    {
        $rate = self::cents($visit['eventRateSyp']);
        $numerator = bcmul((string) $rate, (string) ($minutes * $workers), 0);
        if ($visit['eventRateMinutes'] === 30) {
            // Legacy rate was rounded up to a full lira per visit.
            $whole = bcdiv($numerator, '3000', 0);
            $amount = bcmul(bcadd($whole, bccomp($numerator, bcmul($whole, '3000', 0), 0) > 0 ? '1' : '0', 0), '100', 0);
        } else {
            $amount = bcdiv(bcadd($numerator, '30', 0), '60', 0);
        }
        if (bccomp($amount, '99999999999999', 0) > 0) {
            throw new DomainException('V3_SETTLEMENT_AMOUNT_OVERFLOW');
        }
        return (int) $amount;
    }

    /** Match the existing commission policy exactly (whole-lira ceiling for a percentage). */
    public static function commissionCents(int $serviceCents, string $type, int $rateCents): int
    {
        if ($serviceCents < 0 || $rateCents < 0 || ! in_array($type, ['fixed', 'percent'], true)
            || ($type === 'percent' && $rateCents > 10000)) {
            throw new DomainException('INVALID_V3_SETTLEMENT_BASIS');
        }
        return $type === 'fixed' ? $rateCents
            : (int) bcdiv(bcadd(bcmul((string) $serviceCents, (string) $rateCents, 0), '999999', 0), '1000000', 0) * 100;
    }

    public static function cents(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D', $value) !== 1) {
            throw new DomainException('INVALID_V3_SETTLEMENT_AMOUNT');
        }
        return (int) str_replace('.', '', $value);
    }

    public static function money(int $cents): string
    {
        if ($cents < 0 || $cents > 99999999999999) {
            throw new DomainException('V3_SETTLEMENT_AMOUNT_OVERFLOW');
        }
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
