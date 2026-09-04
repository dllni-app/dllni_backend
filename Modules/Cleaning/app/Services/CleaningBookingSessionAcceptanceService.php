<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Throwable;

final class CleaningBookingSessionAcceptanceService
{
    public function __construct(
        private readonly WorkerBookingScheduleConflictService $scheduleConflictService,
        private readonly CleaningBookingSessionSolvencyService $solvencyService,
        private readonly CleaningBookingSessionWorkerPricingService $pricingService,
        private readonly CleaningBookingSessionCoverageService $coverageService,
        private readonly CleaningBookingSessionWorkerEligibilityService $eligibilityService,
    ) {}

    /**
     * Accept every currently open session for a parent booking or accept none.
     *
     * @return array{allAccepted:bool,acceptedSessionIds:array<int,int>,rejected:array<int,array{sessionId:int,reasonCode:string,message:string}>}
     */
    public function acceptAllAvailableSessions(CleaningBooking $booking, Worker $worker): array
    {
        return DB::transaction(function () use ($booking, $worker): array {
            $this->lockWorkerFinancialRow($worker);

            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->whereIn('status', [
                    CleaningBookingSessionStatus::Scheduled->value,
                    CleaningBookingSessionStatus::WorkerAssigned->value,
                ])
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $sessions = $sessions->filter(
                fn (CleaningBookingSession $session): bool => ! $this->workerAlreadyAccepted($session, $worker)
                    && $session->remainingWorkerCount() > 0
            )->values();

            if ($sessions->isEmpty()) {
                return $this->result(true, [], []);
            }

            $preflight = $this->preflightAll($booking, $sessions, $worker);
            if ($preflight !== []) {
                return $this->result(false, [], $preflight);
            }

            $accepted = [];
            foreach ($sessions as $session) {
                $assignment = $this->createAcceptedAssignment($session, $worker);
                $accepted[] = (int) $assignment->cleaning_booking_session_id;
                $this->scheduleConflictService->forgetWorker($worker);
                $this->coverageService->refresh($session);
            }

            return $this->result(true, $accepted, []);
        }, 3);
    }

    /**
     * Selected-session mode intentionally permits partial success: each selected
     * session is independently validated and invalid sessions remain open.
     *
     * @param  array<int, int>  $sessionIds
     * @return array{allAccepted:bool,acceptedSessionIds:array<int,int>,rejected:array<int,array{sessionId:int,reasonCode:string,message:string}>}
     */
    public function acceptSelectedSessions(CleaningBooking $booking, Worker $worker, array $sessionIds): array
    {
        $requestedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $sessionIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($requestedIds === []) {
            return $this->result(false, [], [[
                'sessionId' => 0,
                'reasonCode' => 'no_sessions_selected',
                'message' => 'Select at least one session.',
            ]]);
        }

        return DB::transaction(function () use ($booking, $worker, $requestedIds): array {
            $this->lockWorkerFinancialRow($worker);

            /** @var Collection<int, CleaningBookingSession> $sessions */
            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->whereIn('id', $requestedIds)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $accepted = [];
            $rejected = [];

            foreach ($requestedIds as $sessionId) {
                /** @var CleaningBookingSession|null $session */
                $session = $sessions->get($sessionId);

                if (! $session instanceof CleaningBookingSession) {
                    $rejected[] = $this->rejection($sessionId, 'session_not_found', 'The selected session does not belong to this booking.');

                    continue;
                }

                if ($this->workerAlreadyAccepted($session, $worker)) {
                    $accepted[] = $sessionId;

                    continue;
                }

                $reason = $this->validateOne($booking, $session, $worker);
                if ($reason !== null) {
                    $rejected[] = $reason;

                    continue;
                }

                try {
                    $assignment = $this->createAcceptedAssignment($session, $worker);
                    $accepted[] = (int) $assignment->cleaning_booking_session_id;
                    $this->scheduleConflictService->forgetWorker($worker);
                    $this->coverageService->refresh($session);
                } catch (Throwable $exception) {
                    report($exception);
                    $rejected[] = $this->rejection(
                        $sessionId,
                        'acceptance_failed',
                        'The session could not be accepted. Refresh the booking and try again.',
                    );
                }
            }

            return $this->result($rejected === [] && count($accepted) === count($requestedIds), $accepted, $rejected);
        }, 3);
    }

    /**
     * @param  Collection<int, CleaningBookingSession>  $sessions
     * @return array<int, array{sessionId:int,reasonCode:string,message:string}>
     */
    private function preflightAll(CleaningBooking $booking, Collection $sessions, Worker $worker): array
    {
        $rejected = [];
        $remainingCapacity = (float) ($this->solvencyService->capacitySummary($worker)['availableCommissionCapacity'] ?? 0.0);
        $selectedIntervals = [];

        foreach ($sessions as $session) {
            $basic = $this->validateSeatAndWorker($booking, $session, $worker);
            if ($basic !== null) {
                $rejected[] = $basic;

                continue;
            }

            $start = $session->startsAt();
            $end = $session->endsAt();
            if ($start === null || $end === null) {
                $rejected[] = $this->rejection((int) $session->id, 'invalid_schedule', 'The session schedule is invalid.');

                continue;
            }

            foreach ($selectedIntervals as $selected) {
                if ($start->lt($selected['end']) && $end->gt($selected['start'])) {
                    $rejected[] = $this->rejection(
                        (int) $session->id,
                        'selected_sessions_overlap',
                        'Two selected sessions overlap in the worker schedule.',
                    );

                    continue 2;
                }
            }

            if ($this->scheduleConflictService->hasConflictForSession($worker, $session)) {
                $rejected[] = $this->rejection((int) $session->id, 'schedule_conflict', 'This session conflicts with another accepted booking.');

                continue;
            }

            try {
                $quote = $this->pricingService->quoteForNextSeat($session, $worker, $session->acceptedWorkerCount());
            } catch (Throwable $exception) {
                report($exception);
                $rejected[] = $this->rejection((int) $session->id, 'pricing_unavailable', 'Worker pricing could not be finalized for this session.');

                continue;
            }

            $requiredCommission = max(0.0, (float) $quote['adminMarginAmount']);
            if ($remainingCapacity < $requiredCommission) {
                $rejected[] = $this->rejection(
                    (int) $session->id,
                    'insufficient_commission_capacity',
                    'The worker financial allowance cannot cover every selected session.',
                );

                continue;
            }

            $remainingCapacity = round($remainingCapacity - $requiredCommission, 2);
            $selectedIntervals[] = ['start' => $start, 'end' => $end];
        }

        return $rejected;
    }

    /** @return array{sessionId:int,reasonCode:string,message:string}|null */
    private function validateOne(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): ?array {
        $basic = $this->validateSeatAndWorker($booking, $session, $worker);
        if ($basic !== null) {
            return $basic;
        }

        $this->scheduleConflictService->forgetWorker($worker);
        if ($this->scheduleConflictService->hasConflictForSession($worker, $session)) {
            return $this->rejection((int) $session->id, 'schedule_conflict', 'This session conflicts with another accepted booking.');
        }

        try {
            $quote = $this->pricingService->quoteForNextSeat($session, $worker, $session->acceptedWorkerCount());
        } catch (Throwable $exception) {
            report($exception);

            return $this->rejection((int) $session->id, 'pricing_unavailable', 'Worker pricing could not be finalized for this session.');
        }

        if (! $this->solvencyService->canCover($worker, (float) $quote['adminMarginAmount'])) {
            return $this->rejection(
                (int) $session->id,
                'insufficient_commission_capacity',
                'The worker financial allowance cannot cover this session.',
            );
        }

        return null;
    }

    /** @return array{sessionId:int,reasonCode:string,message:string}|null */
    private function validateSeatAndWorker(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): ?array {
        $status = $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;

        if ($status === CleaningBookingSessionStatus::Paused->value) {
            return $this->rejection((int) $session->id, 'session_paused', 'This recurring visit is paused and cannot be accepted.');
        }

        if ($session->isTerminal()) {
            return $this->rejection((int) $session->id, 'session_closed', 'This session is no longer open for acceptance.');
        }

        if (! in_array($status, [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return $this->rejection((int) $session->id, 'session_not_open', 'This session is not open for worker acceptance.');
        }

        if ($session->remainingWorkerCount() <= 0) {
            return $this->rejection((int) $session->id, 'session_fully_covered', 'All worker seats for this session are already filled.');
        }

        $eligibility = $this->eligibilityService->check($booking, $session, $worker);
        if (! $eligibility['eligible']) {
            return $this->rejection(
                (int) $session->id,
                $eligibility['reasonCode'],
                $eligibility['message'],
            );
        }

        return null;
    }

    private function createAcceptedAssignment(
        CleaningBookingSession $session,
        Worker $worker,
    ): CleaningBookingSessionWorkerAssignment {
        $acceptedBefore = $session->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->count();

        $quote = $this->pricingService->quoteForNextSeat($session, $worker, $acceptedBefore);
        $this->solvencyService->assertCanCover($worker, (float) $quote['adminMarginAmount']);

        $assignment = CleaningBookingSessionWorkerAssignment::query()->firstOrNew([
            'cleaning_booking_session_id' => $session->id,
            'worker_id' => $worker->id,
        ]);

        $assignment->forceFill([
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
            'released_at' => null,
            'released_reason' => null,
            'started_travel_at' => null,
            'arrived_at' => null,
            'last_latitude' => null,
            'last_longitude' => null,
            'location_updated_at' => null,
            'start_approved_at' => null,
            'work_started_at' => null,
            'work_finished_at' => null,
            'worker_completion_message' => null,
            'service_share_amount' => $quote['serviceShareAmount'],
            'travel_fee' => $quote['travelFee'],
            'admin_margin_amount' => $quote['adminMarginAmount'],
            'worker_amount' => $quote['workerAmount'],
            'currency' => $quote['currency'],
        ])->save();

        return $assignment->fresh() ?? $assignment;
    }

    private function workerAlreadyAccepted(CleaningBookingSession $session, Worker $worker): bool
    {
        return $session->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
    }

    private function lockWorkerFinancialRow(Worker $worker): void
    {
        CleaningWorkerDeposit::query()
            ->where('worker_id', $worker->id)
            ->lockForUpdate()
            ->first();
    }

    /** @return array{sessionId:int,reasonCode:string,message:string} */
    private function rejection(int $sessionId, string $reasonCode, string $message): array
    {
        return compact('sessionId', 'reasonCode', 'message');
    }

    /**
     * @param  array<int, int>  $accepted
     * @param  array<int, array{sessionId:int,reasonCode:string,message:string}>  $rejected
     * @return array{allAccepted:bool,acceptedSessionIds:array<int,int>,rejected:array<int,array{sessionId:int,reasonCode:string,message:string}>}
     */
    private function result(bool $allAccepted, array $accepted, array $rejected): array
    {
        return [
            'allAccepted' => $allAccepted,
            'acceptedSessionIds' => array_values(array_unique($accepted)),
            'rejected' => array_values($rejected),
        ];
    }
}
