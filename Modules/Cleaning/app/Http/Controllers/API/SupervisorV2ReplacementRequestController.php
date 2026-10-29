<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV2VisitReplacementRequestService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

final class SupervisorV2ReplacementRequestController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitReplacementRequestService $replacements,
    ) {}

    public function index(Request $request, int $booking, int $visit): JsonResponse
    {
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->replacements->status($request->user(), $order, $session));
    }

    public function store(Request $request, int $booking, int $visit): JsonResponse
    {
        $input = $request->validate([
            'workerId' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->replacements->request(
            $request->user(), $order, $session,
            (int) $input['workerId'], (string) $input['reason'],
        ));
    }

    public function nominate(Request $request, int $booking, int $visit, int $replacement): JsonResponse
    {
        $input = $request->validate(['workerId' => ['required', 'integer', 'min:1']]);
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->replacements->nominate(
            $request->user(), $order, $session, $replacement, (int) $input['workerId'],
        ));
    }

    public function reallocate(Request $request, int $booking, int $visit, int $replacement): JsonResponse
    {
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->replacements->reallocate($request->user(), $order, $session, $replacement));
    }

    private function visit(Request $request, int $booking, int $visit): array
    {
        $order = $this->scheduled->assignedBooking($request->user(), $booking);
        $session = $this->scheduled->assignedSessions($request->user(), $order)
            ->where('sequence', $visit)->firstOrFail();

        return [$order, $session];
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
