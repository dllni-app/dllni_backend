<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitWorkSegmentService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

/** Visit-scoped supervisor controls; never starts worker work on their behalf. */
final class SupervisorV2VisitWorkController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitWorkSegmentService $work,
    ) {}

    public function start(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->transition($request, $booking, $visit, 'start');
    }

    public function finish(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->transition($request, $booking, $visit, 'finish');
    }

    private function transition(Request $request, int $booking, int $visit, string $action): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        $data = match ($action) {
            'start' => $this->work->supervisorStart($request->user(), $order, $session),
            'finish' => $this->work->supervisorFinish($request->user(), $order, $session),
            default => abort(404),
        };

        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
