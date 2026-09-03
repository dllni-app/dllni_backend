<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionAcceptanceService;

final class CleaningBookingSessionAcceptanceController
{
    public function __construct(
        private readonly CleaningBookingSessionAcceptanceService $acceptanceService,
    ) {}

    public function acceptAll(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $this->worker($request);
        $this->assertBookingOpen($cleaning_booking);

        $result = $this->acceptanceService->acceptAllAvailableSessions($cleaning_booking, $worker);

        return response()->json([
            'success' => $result['allAccepted'],
            'message' => $result['allAccepted']
                ? 'All available sessions were accepted.'
                : 'All sessions could not be accepted. Review the rejected sessions and select the sessions you can accept.',
            'data' => [
                'acceptance' => $result,
                'coverage' => $this->coverage($cleaning_booking),
            ],
        ], $result['allAccepted'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function acceptSelected(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $validated = $request->validate([
            'sessionIds' => ['required_without:session_ids', 'array', 'min:1'],
            'sessionIds.*' => ['integer', 'min:1'],
            'session_ids' => ['required_without:sessionIds', 'array', 'min:1'],
            'session_ids.*' => ['integer', 'min:1'],
        ]);

        $worker = $this->worker($request);
        $this->assertBookingOpen($cleaning_booking);

        if (
            (string) $cleaning_booking->property_type === 'event_assistance'
            && CleaningBookingSession::query()->where('cleaning_booking_id', $cleaning_booking->id)->count() > 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Multi-day event assistance requires accepting every event day in phase one.',
                'data' => [
                    'acceptance' => [
                        'allAccepted' => false,
                        'acceptedSessionIds' => [],
                        'rejected' => [[
                            'sessionId' => 0,
                            'reasonCode' => 'event_all_days_required',
                            'message' => 'You must be available for and accept all event days.',
                        ]],
                    ],
                    'coverage' => $this->coverage($cleaning_booking),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sessionIds = $validated['sessionIds'] ?? $validated['session_ids'] ?? [];
        $result = $this->acceptanceService->acceptSelectedSessions($cleaning_booking, $worker, $sessionIds);

        return response()->json([
            'success' => $result['acceptedSessionIds'] !== [],
            'message' => $result['rejected'] === []
                ? 'Selected sessions were accepted.'
                : 'Some selected sessions could not be accepted.',
            'data' => [
                'acceptance' => $result,
                'coverage' => $this->coverage($cleaning_booking),
            ],
        ]);
    }

    private function worker(Request $request): Worker
    {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        return $worker;
    }

    private function assertBookingOpen(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        if (in_array($status, [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Booking is already closed.');
        }

        if (! CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)->exists()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'This booking does not contain execution sessions.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function coverage(CleaningBooking $booking): array
    {
        return CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->with('workerAssignments:id,cleaning_booking_session_id,worker_id,status,accepted_at')
            ->orderBy('sequence')
            ->get()
            ->map(static function (CleaningBookingSession $session): array {
                return [
                    'sessionId' => (int) $session->id,
                    'sequence' => (int) $session->sequence,
                    'coverageStatus' => $session->coverage_status?->value ?? (string) $session->coverage_status,
                    'requiredWorkers' => $session->requiredWorkerCount(),
                    'acceptedWorkers' => $session->acceptedWorkerCount(),
                    'remainingWorkers' => $session->remainingWorkerCount(),
                    'workerIds' => $session->workerAssignments
                        ->filter(static fn ($assignment): bool => $assignment->isAccepted())
                        ->pluck('worker_id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }
}
