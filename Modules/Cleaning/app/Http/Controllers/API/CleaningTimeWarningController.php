<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningAcceptRequest;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningRejectRequest;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningRequests\CleaningTimeWarningFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningTimeWarningResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningTimeWarningService;
use Throwable;

final class CleaningTimeWarningController
{
    public function __construct(
        private CleaningTimeWarningService $cleaningTimeWarningService
    ) {}

    public function index(CleaningTimeWarningFilterRequest $request): AnonymousResourceCollection
    {
        $warnings = CleaningTimeWarning::getQuery()
            ->with(['booking'])
            ->paginate($request->get('perPage', 20));

        return CleaningTimeWarningResource::collection($warnings);
    }

    public function show(CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $cleaning_time_warning->load(['booking']);

        return CleaningTimeWarningResource::make($cleaning_time_warning);
    }

    /** @throws Throwable */
    public function accept(CleaningTimeWarningAcceptRequest $request, CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $this->ensureWorkerOwnsWarning($cleaning_time_warning);

        try {
            $warning = $this->cleaningTimeWarningService->accept(
                $cleaning_time_warning,
                $request->validated('additionalMinutes')
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['warning' => [$e->getMessage()]]);
        }

        return CleaningTimeWarningResource::make($warning->load(['booking']));
    }

    /** @throws Throwable */
    public function reject(CleaningTimeWarningRejectRequest $request, CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $this->ensureWorkerOwnsWarning($cleaning_time_warning);

        try {
            $warning = $this->cleaningTimeWarningService->reject(
                $cleaning_time_warning,
                $request->validated('message')
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['warning' => [$e->getMessage()]]);
        }

        return CleaningTimeWarningResource::make($warning->load(['booking']));
    }

    private function ensureWorkerOwnsWarning(CleaningTimeWarning $warning): void
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            abort(403, 'User must have an associated worker.');
        }

        if ($warning->booking_type !== 'cleaning_booking') {
            abort(403, 'Extension request is not for a cleaning booking.');
        }

        $booking = $warning->booking;

        if (! $booking instanceof CleaningBooking || $booking->worker_id !== $worker->id) {
            abort(403, 'Extension request is not for your booking.');
        }
    }
}
