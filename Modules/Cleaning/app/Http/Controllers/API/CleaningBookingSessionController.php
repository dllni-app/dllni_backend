<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\User\Http\Resources\UserCleaningSosResource;

final class CleaningBookingSessionController
{
    public function __construct(
        private readonly CleaningBookingSessionLifecycleService $lifecycle,
    ) {}

    public function startTravel(Request $request, CleaningBooking $booking, CleaningBookingSession $session): CleaningBookingResource
    {
        return $this->bookingResponse(fn () => $this->lifecycle->startTravel($booking, $session, $this->worker($request)));
    }

    public function location(Request $request, CleaningBooking $booking, CleaningBookingSession $session): CleaningBookingResource
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->bookingResponse(fn () => $this->lifecycle->updateLocation(
            $booking,
            $session,
            $this->worker($request),
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        ));
    }

    public function arrive(Request $request, CleaningBooking $booking, CleaningBookingSession $session): CleaningBookingResource
    {
        return $this->bookingResponse(fn () => $this->lifecycle->arrive($booking, $session, $this->worker($request)));
    }

    public function securityCode(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        try {
            $generated = $this->lifecycle->issueSecurityCode($booking, $session, $this->worker($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        return response()->json([
            'message' => __('Security code generated successfully.'),
            'data' => $generated,
        ]);
    }

    public function startWork(Request $request, CleaningBooking $booking, CleaningBookingSession $session): CleaningBookingResource
    {
        return $this->bookingResponse(fn () => $this->lifecycle->startWork($booking, $session, $this->worker($request)));
    }

    public function complete(Request $request, CleaningBooking $booking, CleaningBookingSession $session): CleaningBookingResource
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'completionMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->bookingResponse(fn () => $this->lifecycle->complete(
            $booking,
            $session,
            $this->worker($request),
            $validated['completionMessage'] ?? $validated['message'] ?? null,
        ));
    }

    public function sos(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        $validated = $request->validate([
            'emergency_type' => ['required', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $sos = $this->lifecycle->createSos($booking, $session, $this->worker($request), $validated);

        return response()->json([
            'success' => true,
            'message' => 'Cleaning booking session SOS request sent successfully.',
            'data' => UserCleaningSosResource::make($sos)->resolve($request),
        ], 201);
    }

    private function worker(Request $request): Worker
    {
        $worker = $request->user()?->worker;
        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }
        return $worker;
    }

    /** @param callable():CleaningBooking $callback */
    private function bookingResponse(callable $callback): CleaningBookingResource
    {
        try {
            $booking = $callback();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        return CleaningBookingResource::make($booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]));
    }
}
