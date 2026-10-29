<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2SupervisorVisitAttendanceService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

final class SupervisorV2VisitAttendanceController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2SupervisorVisitAttendanceService $attendance,
    ) {}

    public function __invoke(Request $request, int $booking, int $visit, string $action): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        return response()->json(['success' => true, 'data' => [
            'visitId' => $session->id,
            'attendance' => $this->attendance->transition($request->user(), $order, $session, $action),
            'executionEnabled' => false,
        ]]);
    }
}
