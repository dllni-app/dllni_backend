<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Read-only V3 time basis computed from server-verified work interval seconds.
 *
 * Do not invoke with client-reported duration or mutable price rules. The
 * minimum, step and ceiling must come from the immutable approved quote.
 * This calculator never computes money or authorizes financial settlement.
 */
final class CleaningV3ActiveTimeBillingCalculator
{
    /**
     * @return array<string, int|bool>
     */
    public function calculate(
        int $activeSeconds,
        int $minimumMinutes,
        int $roundingStepMinutes,
        int $maximumBillableMinutes = 480,
    ): array {
        if ($activeSeconds < 0
            || $activeSeconds > 480 * 60
            || $minimumMinutes < 1 || $minimumMinutes > 480
            || $roundingStepMinutes < 1 || $roundingStepMinutes > 60
            || $maximumBillableMinutes < $minimumMinutes
            || $maximumBillableMinutes > 480) {
            throw new DomainException('INVALID_V3_TIME_BILLING_RULE');
        }

        // Unstarted, skipped or pre-work cancelled visits have no worked-time
        // charge. Cancellation penalties, if any, live in a separate policy.
        $wholeMinutes = $activeSeconds === 0 ? 0 : intdiv($activeSeconds + 59, 60);
        $billableMinutes = 0;

        if ($wholeMinutes > 0) {
            $covered = max($wholeMinutes, $minimumMinutes);
            $aboveMinimum = $covered - $minimumMinutes;
            $roundedAboveMinimum = intdiv(
                $aboveMinimum + $roundingStepMinutes - 1,
                $roundingStepMinutes,
            ) * $roundingStepMinutes;
            $billableMinutes = $minimumMinutes + $roundedAboveMinimum;
            if ($billableMinutes > $maximumBillableMinutes) {
                throw new DomainException('V3_TIME_BILLING_LIMIT_EXCEEDED');
            }
        }

        return [
            'activeSeconds' => $activeSeconds,
            'activeMinutesRoundedUp' => $wholeMinutes,
            'billableMinutes' => $billableMinutes,
            'minimumMinutes' => $minimumMinutes,
            'roundingStepMinutes' => $roundingStepMinutes,
            'maximumBillableMinutes' => $maximumBillableMinutes,
            'canCharge' => false,
            'canSettle' => false,
        ];
    }
}
