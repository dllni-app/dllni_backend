<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Http\Requests\CleaningBookingRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRequests\CleaningBookingFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingService;
use Throwable;

final class CleaningBookingController
{
    public function __construct(
        private CleaningBookingService $cleaningBookingService
    ) {}

    public function index(CleaningBookingFilterRequest $request): AnonymousResourceCollection
    {
        $bookings = CleaningBooking::getQuery()
            ->with(['customer', 'worker'])
            ->paginate($request->get('perPage', 20));

        return CleaningBookingResource::collection($bookings);
    }

    /** @throws Throwable */
    public function store(CleaningBookingRequest $request): CleaningBookingResource
    {
        $booking = $this->cleaningBookingService->store(
            CleaningBookingData::from($request->validated())
        );

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    public function show(CleaningBooking $cleaning_booking): CleaningBookingResource
    {
        $cleaning_booking->load([
            'customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes',
        ]);

        return CleaningBookingResource::make($cleaning_booking);
    }

    /** @throws Throwable */
    public function update(CleaningBookingRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource
    {
        $updated = $this->cleaningBookingService->update(
            CleaningBookingData::from($request->validated()),
            $cleaning_booking
        );

        return CleaningBookingResource::make(
            $updated->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    public function destroy(CleaningBooking $cleaning_booking): Response
    {
        $cleaning_booking->delete();

        return response()->noContent();
    }
}
