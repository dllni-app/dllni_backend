<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/**
 * Financial diagnostic for a frozen customer quote and recorded liabilities.
 * An external funding amount must be explicitly approved and traceable before
 * it can be counted. This service never creates a funding or settlement entry.
 */
final class CleaningSessionFundingConservationService
{
    /**
     * @param  list<array{workerAmount:mixed,travelFee:mixed}>  $workers
     * @return array{status:string,reasonCodes:list<string>,workerObligations:?string,workerTravelIncluded:?string,minimumUnfunded:?string,fundingProven:bool,withinQuote:bool}
     */
    public function assess(mixed $customerQuote, array $workers, int $requiredWorkers, mixed $supervisorObligation = '0.00', mixed $approvedExternalFunding = null, ?string $fundingEvidenceId = null, bool $fundingEvidenceVerified = false): array
    {
        $reasons = [];
        $quote = $this->cents($customerQuote);
        $supervisor = $this->cents($supervisorObligation);
        $external = $this->cents($approvedExternalFunding);
        if ($quote === null || $quote < 0) {
            $reasons[] = 'customer_quote_invalid';
        }
        if ($supervisor === null || $supervisor < 0) {
            $reasons[] = 'supervisor_obligation_invalid';
        }
        if ($requiredWorkers < 1 || count($workers) !== $requiredWorkers) {
            $reasons[] = 'worker_roster_incomplete';
        }

        $total = 0;
        $travel = 0;
        foreach ($workers as $worker) {
            $amount = $this->cents($worker['workerAmount'] ?? null);
            $fee = $this->cents($worker['travelFee'] ?? null);
            if ($amount === null || $amount <= 0 || $fee === null || $fee < 0 || $fee > $amount) {
                $reasons[] = 'worker_entitlement_invalid';

                continue;
            }
            $total += $amount;
            $travel += $fee;
        }
        $complete = $requiredWorkers > 0 && count($workers) === $requiredWorkers && ! in_array('worker_entitlement_invalid', $reasons, true);
        $gap = $complete && $quote !== null && $quote >= 0 && $supervisor !== null && $supervisor >= 0
            ? max(0, $total + $supervisor - $quote) : null;

        // A positive declared funding amount without documentary evidence is
        // still an uncovered obligation, even if it numerically closes the gap.
        $fundingValid = $fundingEvidenceVerified && $external !== null && $external >= 0 && $fundingEvidenceId !== null && mb_trim($fundingEvidenceId) !== '';
        $withinQuote = $gap === 0 && $reasons === [];
        if (! $fundingValid && ! $withinQuote) {
            $reasons[] = 'approved_funding_evidence_missing';
        }
        if ($gap !== null && $gap > ($fundingValid ? $external : 0)) {
            $reasons[] = 'obligations_exceed_proven_funding';
        }

        return [
            'status' => $reasons === [] ? ($fundingValid ? 'balanced' : 'within_quote') : 'blocked',
            'reasonCodes' => array_values(array_unique($reasons)),
            'workerObligations' => $complete ? $this->format($total) : null,
            'workerTravelIncluded' => $complete ? $this->format($travel) : null,
            'minimumUnfunded' => $gap === null ? null : $this->format(max(0, $gap - ($fundingValid ? $external : 0))),
            'fundingProven' => $reasons === [] && $fundingValid,
            'withinQuote' => $withinQuote,
        ];
    }

    private function cents(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }
        $value = (string) $amount;
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $digits = mb_ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        if (mb_strlen($whole) > 13) {
            return null;
        }
        $cents = ((int) $whole * 100) + (int) mb_str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
