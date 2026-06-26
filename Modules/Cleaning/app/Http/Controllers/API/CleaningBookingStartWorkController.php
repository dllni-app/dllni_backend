<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingPriceAdjustmentService;
use Modules\Cleaning\Services\CleaningBookingService;

final class CleaningBookingStartWorkController
{
    public function __construct(
        private readonly CleaningBookingService $cleaningBookingService,
        private readonly CleaningBookingPriceAdjustmentService $priceAdjustmentService,
    ) {}

    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureCurrentWorkerCanStart($cleaning_booking);

        try {
            $this->priceAdjustmentService->assertNoPendingRequestBeforeStart($cleaning_booking);
            $booking = $this->cleaningBookingService->startWork($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ])
        );
    }

    private function ensureCurrentWorkerCanStart(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if ($worker === null) {
            abort(403, 'User must have an associated worker.');
        }

        $hasAcceptedAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && (int) $booking->worker_id !== (int) $worker->id && ! $hasAcceptedAssignment) {
            abort(403, 'Booking is not available for this worker.');
        }

        if ($booking->worker_id === null && ! $hasAcceptedAssignment) {
            abort(403, 'Booking must be assigned before this action.');
        }
    }
}
