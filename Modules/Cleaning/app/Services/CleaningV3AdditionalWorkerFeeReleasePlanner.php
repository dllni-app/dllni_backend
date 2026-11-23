<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Read-only review for one V3 visit's already-priced extra-worker surcharge.
 *
 * The postedCollectedFee and postedRefundedFee arguments must be sourced
 * exclusively from reconciled, fee-specific ledger movements; a booking
 * balance or quote snapshot is NOT evidence of collection. This class
 * deliberately cannot issue a refund, release a funding hold or update a
 * booking. Any collected fee needs a separate authenticated refund operation
 * carrying an immutable original collection reference and an approval reason.
 */
final class CleaningV3AdditionalWorkerFeeReleasePlanner
{
    /**
     * @return array<string,mixed>
     */
    public function review(
        int $visitId,
        int $pricedWorkers,
        string $visitStatus,
        bool $workEverStarted,
        string $postedCollectedFee,
        string $postedRefundedFee,
    ): array {
        if ($visitId < 1 || $pricedWorkers < 1 || $pricedWorkers > 20
            || ! in_array($visitStatus, [
                'scheduled', 'worker_assigned', 'in_progress', 'paused',
                'awaiting_customer_completion', 'completed', 'cancelled',
                'skipped', 'superseded', 'under_dispute',
            ], true)) {
            throw new DomainException('INVALID_V3_FEE_RELEASE_CONTEXT');
        }

        $collected = $this->parse($postedCollectedFee);
        $refunded = $this->parse($postedRefundedFee);
        $fee = ($pricedWorkers - 1) * 15000000;

        if ($collected > $fee || $refunded > $collected) {
            throw new DomainException('INVALID_V3_FEE_LEDGER_EVIDENCE');
        }

        $beforeWorkRelease = ! $workEverStarted
            && in_array($visitStatus, ['cancelled', 'skipped'], true);

        $remainingCollected = $collected - $refunded;

        return [
            'visitId' => $visitId,
            'currency' => 'SYP',
            'pricedWorkers' => $pricedWorkers,
            'additionalWorkerFee' => $this->money($fee),
            'postedCollectedFee' => $this->money($collected),
            'postedRefundedFee' => $this->money($refunded),
            // Amount not yet collected can be removed from a pending hold
            // on cancellation, but never represented as cash refunded.
            'uncollectedFeeReleaseCandidate' => $this->money(
                $beforeWorkRelease ? $fee - $collected : 0,
            ),
            'postedFeeRefundReviewCeiling' => $this->money(
                $beforeWorkRelease ? $remainingCollected : 0,
            ),
            'manualFeeRefundReviewCeiling' => $this->money($remainingCollected),
            'requiresApprovedOriginalCollectionReference' => $remainingCollected > 0,
            'requiresRefundReasonAndAuthorization' => $remainingCollected > 0,
            'workEverStarted' => $workEverStarted,
            'automaticFeeReleaseEligible' => $beforeWorkRelease,
            'canReleaseFunding' => false,
            'canRefund' => false,
            'canSettle' => false,
            'reviewOnly' => true,
        ];
    }

    private function parse(string $value): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new DomainException('INVALID_V3_FEE_LEDGER_AMOUNT');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) mb_str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
