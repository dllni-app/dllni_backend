<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitFundingPreflightService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

final class SupervisorV2VisitFundingPreflightController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitFundingPreflightService $preflight,
    ) {}

    public function __invoke(Request $request, int $booking, int $visit): JsonResponse
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        return response()->json(['success' => true, 'data' =>
            $this->preflight->review($request->user(), $order, $session),
        ])->header('Cache-Control', 'private, no-store');
    }
}
