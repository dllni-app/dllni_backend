<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Http\Requests\CleaningBookingCancelRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRejectRequest;
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

    /** @throws Throwable */
    public function accept(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: false);

        try {
            $booking = $this->cleaningBookingService->accept($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    /** @throws Throwable */
    public function reject(CleaningBookingRejectRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->reject($cleaning_booking, $request->validated('reason'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    /** @throws Throwable */
    public function startTravel(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->startTravel($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    /** @throws Throwable */
    public function complete(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->complete($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    /** @throws Throwable */
    public function cancel(CleaningBookingCancelRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->cancel($cleaning_booking, $request->validated('reason'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $booking->load(['customer', 'worker', 'services', 'addons', 'billingPolicy', 'timeWarnings', 'disputes'])
        );
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking, bool $requireOwnership = true): void
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            abort(403, 'User must have an associated worker.');
        }

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($requireOwnership && $booking->worker_id === null) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }
}
