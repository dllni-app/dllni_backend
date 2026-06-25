<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
}
