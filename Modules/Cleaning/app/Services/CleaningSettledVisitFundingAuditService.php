<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Read-only legacy settlement audit. Its output is an investigative warning,
 * never authorization to charge/refund, settle a payable, or assume subsidy.
 * Previously settled amounts and statuses must remain untouched.
 */
final class CleaningSettledVisitFundingAuditService
{
    public function __construct(
        private readonly CleaningSessionFundingConservationService $conservation,
    ) {}

    /**
     * @return list<array{session_id:int,sequence:int,reason:string,gap:string,worker_amount:string,travel:string,payment_status:string}>
     */
    public function unresolved(CleaningBooking $booking): array
    {
        $issues = [];
        $visits = $booking->sessions()
            ->where('payment_status', 'settled')
            ->with('workerAssignments')
            ->orderBy('sequence')
            ->get();

        foreach ($visits as $visit) {
            if (! $visit instanceof CleaningBookingSession) {
                continue;
            }

            $workers = [];
            $workerAmountCents = 0;
            $travelCents = 0;

            foreach ($visit->workerAssignments as $assignment) {
                // A released, never-accepted offer is not a settled liability.
                if ($assignment->released_at !== null
                    && $assignment->accepted_at === null) {
                    continue;
                }

                $amount = $this->cents($assignment->worker_amount);
                $travel = $this->cents($assignment->travel_fee);
                $workers[] = [
                    'workerAmount' => $amount === null ? null : $this->money($amount),
                    'travelFee' => $travel === null ? null : $this->money($travel),
                ];
                if ($amount !== null) {
                    $workerAmountCents += $amount;
                }
                if ($travel !== null) {
                    $travelCents += $travel;
                }
            }

            $supervisorObligation = '0.00';
            if ($visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR) {
                // The quote's supervisor fee is not proof of the employee's
                // payable. Only an explicit nonnegative compensation amount
                // may be used, and a missing amount remains unknown.
                $raw = data_get($visit->pricing_snapshot, 'supervisorCompensationAmount')
                    ?? data_get($booking->property_details, '_supervisor_pricing.compensationAmount');
                $cents = $this->cents($raw);
                $supervisorObligation = $cents === null ? null : $this->money($cents);
            }

            $result = $this->conservation->assess(
                $visit->total_price === null ? null : (string) $visit->total_price,
                $workers,
                (int) $visit->required_workers,
                $supervisorObligation,
            );
            if ($result['reasonCodes'] === []) {
                continue;
            }

            $issues[] = [
                'session_id' => (int) $visit->id,
                'sequence' => (int) $visit->sequence,
                'reason' => implode(', ', $result['reasonCodes']),
                'gap' => $result['minimumUnfunded'] ?? 'غير محدد',
                'worker_amount' => $this->money($workerAmountCents),
                'travel' => $this->money($travelCents),
                'payment_status' => 'مسجل كمسدّد',
            ];
        }

        return $issues;
    }

    private function cents(mixed $raw): ?int
    {
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return null;
        }
        $value = (string) $raw;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($whole) > 13) {
            return null;
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
