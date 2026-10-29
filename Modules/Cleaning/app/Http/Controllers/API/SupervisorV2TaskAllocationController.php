<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitTaskAllocationService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

/** Authenticated, active visit supervisor only; allocation is not work-start. */
final class SupervisorV2TaskAllocationController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitTaskAllocationService $allocator,
    ) {}

    public function __invoke(Request $request, int $booking, int $visit): JsonResponse
    {
        $validated = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:100'],
            'tasks.*.taskKey' => ['required', 'string', 'max:160'],
            'tasks.*.workerIds' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.workerIds.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();
        $allocation = $this->allocator->allocate($request->user(), $order, $session, $validated['tasks']);

        return response()->json(['success' => true, 'data' => [
            'visitId' => $session->id,
            'visitSequence' => $session->sequence,
            'tasks' => $allocation,
            'executionEnabled' => false,
        ]]);
    }
}
