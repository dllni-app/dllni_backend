<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitExecutionCapabilityService;
use Modules\Cleaning\Services\CleaningV2VisitFundingPreflightService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

/**
 * Canonical, owner-scoped readiness across task, attendance, customer proof
 * and financial gates. It is informational and cannot authorize transitions.
 */
final class SupervisorV2ExecutionReadinessController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitExecutionCapabilityService $capability,
        private readonly CleaningV2VisitFundingPreflightService $funding,
    ) {}

    public function __invoke(Request $request, int $booking, int $visit): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        $financial = $this->funding->review($request->user(), $order, $session);
        $readiness = $this->capability->readiness($order, $session,
            $financial['fundingProven'] === true && $financial['startWorkAllowed'] === true);

        return response()->json(['success' => true, 'data' => [
            'bookingId' => (int) $order->id,
            'visitId' => (int) $session->id,
            'visitSequence' => (int) $session->sequence,
            'executionCapability' => $readiness['executionCapability'],
            'allowedActions' => $readiness['allowedActions'],
            'reasonCodes' => array_values(array_unique([
                ...$readiness['reasonCodes'], ...$financial['reasonCodes'],
            ])),
            'taskCandidates' => $readiness['taskCandidates'],
            'taskCount' => $session->executionTasks()->count(),
            'activeSupervisorWorkSegments' => $session->supervisorWorkSegments()->where('active_slot', 1)->count(),
            'activeWorkerWorkSegments' => (int) \Illuminate\Support\Facades\DB::table('cleaning_booking_session_task_worker_segments as segment')
                ->join('cleaning_booking_session_task_workers as member', 'member.id', '=', 'segment.cleaning_booking_session_task_worker_id')
                ->join('cleaning_booking_session_tasks as task', 'task.id', '=', 'member.cleaning_booking_session_task_id')
                ->where('task.cleaning_booking_session_id', $session->id)
                ->where('segment.active_slot', 1)
                ->count(),
            'fundingProven' => $financial['fundingProven'],
            'settlementAllowed' => $financial['settlementAllowed'],
        ]])->header('Cache-Control', 'private, no-store');
    }
}
