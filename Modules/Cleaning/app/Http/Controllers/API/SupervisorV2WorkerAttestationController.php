<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Services\CleaningV2VisitWorkerAttestationService;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;

final class SupervisorV2WorkerAttestationController
{
    public function __construct(
        private readonly SupervisorScheduledV2Service $scheduled,
        private readonly CleaningV2VisitWorkerAttestationService $attendance,
    ) {}

    public function index(Request $request, int $booking, int $visit): JsonResponse
    {
        [$order, $session] = $this->visit($request, $booking, $visit);

        return $this->response($this->attendance->status($request->user(), $order, $session));
    }

    public function store(Request $request, int $booking, int $visit): JsonResponse
    {
        $input = $request->validate([
            'workerId' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in(['present', 'no_show'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        [$order, $session] = $this->visit($request, $booking, $visit);
        $data = $this->attendance->certify(
            $request->user(), $order, $session,
            (int) $input['workerId'], (string) $input['outcome'], $input['note'] ?? null,
        );

        return $this->response($data);
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
