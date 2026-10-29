<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitVerificationService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

/** G2C customer-private, supervisor-entered visit verification. No work start. */
final class SupervisorV2VisitVerificationController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitVerificationService $verification,
    ) {}

    public function request(Request $request, int $booking, int $visit): JsonResponse
    {
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->verification->request($request->user(), $order, $session));
    }

    public function status(Request $request, int $booking, int $visit): JsonResponse
    {
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->verification->status($request->user(), $order, $session));
    }

    public function confirm(Request $request, int $booking, int $visit): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'digits:6']]);
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->verification->confirm($request->user(), $order, $session, (string) $input['code']));
    }

    private function visit(Request $request, int $booking, int $visit): array
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        return [$order, $session];
    }

    private function response(array $result): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $result])
            ->header('Cache-Control', 'private, no-store');
    }
}
