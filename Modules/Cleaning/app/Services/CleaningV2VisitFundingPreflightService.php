<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Read-only liability/quote reconciliation. A nonnegative projection never
 * constitutes approved funding or authorizes work, completion or settlement.
 */
final class CleaningV2VisitFundingPreflightService
{
    public function __construct(
        private readonly CleaningSupervisorEarnedCompensationService $earnedCompensation,
        private readonly CleaningV2FundingReservationAuditService $fundingAudit,
    ) {}

    /** @return array<string,mixed> */
    public function review(User $actor, CleaningBooking $booking, CleaningBookingSession $session): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        if ((int) $session->cleaning_booking_id !== (int) $booking->id || ! $booking->isV2OperationalGroup()) {
            abort(404);
        }
        $owners = $session->supervisorAssignments()->where('active_slot', 1)
            ->whereNull('released_at')->get();
        if ($owners->count() !== 1 || (int) $owners->first()->supervisor_user_id !== (int) $actor->id) {
            abort(404);
        }

        $quote = $this->money($session->getRawOriginal('total_price'));
        $bookingQuote = $this->money($booking->getRawOriginal('total_price'));
        $owner = $owners->first();
        $hourlyRate = $this->money($owner->getRawOriginal('compensation_hourly_rate'));
        $scheduledHours = $session->duration_hours === null ? null : (float) $session->duration_hours;
        $supervisorProjected = $hourlyRate !== null && $hourlyRate > 0 && $scheduledHours !== null && $scheduledHours > 0
            ? round($hourlyRate * $scheduledHours, 2) : null;
        // Actual supervisor earnings are never inferred from the visit's
        // scheduled hours. A closed, attributed work segment is required.
        $earned = $this->earnedCompensation->assess($owner);
        $supervisorEarned = $earned['status'] === 'calculated' && $earned['earnedAmount'] !== null
            ? $this->money($earned['earnedAmount']) : null;
        $workFinished = $session->work_finished_at !== null;
        $accepted = $session->workerAssignments()->whereNull('released_at')
            ->whereNotNull('accepted_at')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())->get();
        $workerAmounts = $accepted->map(fn ($worker): ?float => $this->money($worker->getRawOriginal('worker_amount')));
        $travelAmounts = $accepted->map(fn ($worker): ?float => $this->money($worker->getRawOriginal('travel_fee')));
        $workerLiability = $workerAmounts->count() === $session->requiredWorkerCount()
            && $workerAmounts->every(fn ($value) => $value !== null && $value > 0)
                ? round((float) $workerAmounts->sum(), 2) : null;
        $workerTravelLiability = $travelAmounts->count() === $session->requiredWorkerCount()
            && $travelAmounts->every(fn ($value) => $value !== null && $value >= 0)
                ? round((float) $travelAmounts->sum(), 2) : null;
        // Worker amount already includes travel where applicable: don't add it twice.
        $supervisorLiabilityForReview = $workFinished ? $supervisorEarned : $supervisorProjected;
        $projectedKnownMinimum = $workerLiability !== null && $supervisorLiabilityForReview !== null
            ? round($workerLiability + $supervisorLiabilityForReview, 2) : null;
        $unfundedLowerBound = $projectedKnownMinimum !== null && $quote !== null && $quote >= 0
            ? round(max(0, $projectedKnownMinimum - $quote), 2) : null;

        $reasons = [];
        if ($quote === null || $quote <= 0 || ! $session->is_pricing_final) {
            $reasons[] = 'approved_visit_quote_unavailable';
        }
        if ($bookingQuote === null || $bookingQuote <= 0 || ! $booking->is_pricing_final) {
            $reasons[] = 'booking_quote_unavailable';
        }
        if ($workerLiability === null || $workerTravelLiability === null) {
            $reasons[] = 'worker_liabilities_incomplete';
        }
        if ($supervisorProjected === null) {
            $reasons[] = 'supervisor_compensation_unconfigured';
        }
        if ($workFinished && $supervisorEarned === null) {
            $reasons[] = 'supervisor_earned_compensation_unproven';
        }
        if ($unfundedLowerBound !== null && $unfundedLowerBound > 0) {
            $reasons[] = 'known_obligations_exceed_visit_quote';
        }
        // Additional read-only evidence: a registry row or admin-approved
        // reservation is NOT independent proof of cleared spendable funds.
        $fundingRegistry = $this->fundingAudit->inspect($session);
        // No integrated independent clearing verifier or transactional payout
        // ledger exists for V2 group visits. Never treat a zero gap as proof.
        $reasons[] = 'approved_funding_source_unproven';

        return [
            'status' => 'blocked',
            'reasonCodes' => array_values(array_unique($reasons)),
            'bookingCustomerQuote' => $bookingQuote,
            'visitCustomerQuote' => $quote,
            'workerEntitlementsTotal' => $workerLiability,
            'workerTravelIncludedTotal' => $workerTravelLiability,
            'supervisorProjectedShare' => $supervisorProjected,
            'supervisorEarnedShare' => $supervisorEarned,
            'supervisorEarnedMinutes' => $earned['minutes'],
            'supervisorEarnedReasonCodes' => $earned['reasonCodes'],
            'knownObligationsMinimum' => $projectedKnownMinimum,
            'unfundedMinimum' => $unfundedLowerBound,
            'fundingRegistry' => $fundingRegistry,
            'currency' => (string) ($session->pricing_snapshot['currency'] ?? config('app.currency', 'SYP')),
            'fundingProven' => false,
            'startWorkAllowed' => false,
            'settlementAllowed' => false,
        ];
    }

    private function money(mixed $raw): ?float
    {
        return $raw !== null && is_numeric($raw) && is_finite((float) $raw)
            ? round((float) $raw, 2) : null;
    }
}
