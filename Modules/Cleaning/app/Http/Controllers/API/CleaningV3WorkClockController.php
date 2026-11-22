<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3WorkClockProjectionService;
use Modules\Cleaning\Services\SupervisedTeamService;

/**
 * Exact-visit, read-only V3 work clock.
 *
 * Customers see their own visits; workers need a currently accepted,
 * non-released assignment for THIS session; supervisors need a currently
 * active assignment through the existing supervised-team authorization.
 * Offer candidates, withdrawn workers and historical supervisors get 404.
 */
final class CleaningV3WorkClockController
{
    public function __invoke(
        Request $request,
        int $booking,
        int $session,
        CleaningV3WorkClockProjectionService $clock,
    ): JsonResponse {
        $parent = CleaningBooking::query()->findOrFail($booking);
        abort_unless((string) ($parent->contract_version ?? '') === 'v3', 404);

        $visit = $parent->sessions()->whereKey($session)->firstOrFail();

        abort_unless($this->canRead($request->user(), $parent, $visit), 404);

        return response()->json([
            'success' => true,
            'data' => $clock->project($visit),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function canRead(?User $actor, CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        if (! $actor instanceof User || ! $actor->is_active) {
            return false;
        }
        if ((int) $actor->id === (int) $booking->customer_id) {
            return true;
        }

        $worker = $actor->worker;
        if ($worker instanceof Worker
            && $visit->workerAssignments()
                ->where('worker_id', $worker->id)
                ->whereNull('released_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->exists()) {
            return true;
        }

        return $actor->hasRole(SupervisedTeamService::ROLE)
            && app(SupervisedTeamService::class)->assignedQuery($actor)
                ->whereKey($booking->getKey())
                ->exists();
    }
}
