<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Data\EventBookingData;
use Modules\Cleaning\Http\Requests\EventBookingRequest;
use Modules\Cleaning\Http\Requests\EventBookingRequests\EventBookingFilterRequest;
use Modules\Cleaning\Http\Resources\EventBookingResource;
use Modules\Cleaning\Models\EventBooking;
use Modules\Cleaning\Services\EventBookingService;
use Throwable;

final class EventBookingController
{
    public function __construct(
        private readonly EventBookingService $eventBookingService
    ) {}

    public function index(EventBookingFilterRequest $request): AnonymousResourceCollection
    {
        $bookings = EventBooking::getQuery()
            ->with(['customer'])
            ->paginate($request->get('perPage', 20));

        return EventBookingResource::collection($bookings);
    }

    /** @throws Throwable */
    public function store(EventBookingRequest $request): EventBookingResource
    {
        $booking = $this->eventBookingService->store(
            EventBookingData::from($request->validated())
        );

        return EventBookingResource::make(
            $booking->load(['customer', 'services', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    public function show(EventBooking $event_booking): EventBookingResource
    {
        $event_booking->load([
            'customer', 'services', 'billingPolicy', 'timeWarnings', 'disputes',
        ]);

        return EventBookingResource::make($event_booking);
    }

    /** @throws Throwable */
    public function update(EventBookingRequest $request, EventBooking $event_booking): EventBookingResource
    {
        $updated = $this->eventBookingService->update(
            EventBookingData::from($request->validated()),
            $event_booking
        );

        return EventBookingResource::make(
            $updated->load(['customer', 'services', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    public function destroy(EventBooking $event_booking): Response
    {
        $event_booking->delete();

        return response()->noContent();
    }
}
