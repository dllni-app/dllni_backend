<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningOpenTimeExtension;
use Modules\Cleaning\Services\CleaningOpenTimeLifecycleService;

final class CleaningOpenTimeController
{
    public function meter(Request $request, CleaningBooking $cleaning_booking, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $worker = $this->worker($request);

        return response()->json(['success' => true, 'data' => ['openTime' => $service->meter($cleaning_booking, $worker)]]);
    }

    public function decideExtension(Request $request, CleaningOpenTimeExtension $extension, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $resolved = $service->decideExtension($extension, $this->worker($request), $validated['decision'], $validated['reason'] ?? null);
        $booking = $resolved->booking()->firstOrFail();
        $openTime = $resolved->cleaning_booking_session_id === null
            ? $service->meter($booking, $this->worker($request))
            : $service->meterSession($booking, $resolved->session()->firstOrFail(), $this->worker($request));

        return response()->json(['success' => true, 'data' => [
            'extension' => $resolved,
            'openTime' => $openTime,
        ]]);
    }

    public function decideEnd(Request $request, CleaningBooking $cleaning_booking, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $booking = $service->decideEnd($cleaning_booking, $this->worker($request), $validated['decision'], $validated['reason'] ?? null);

        return response()->json(['success' => true, 'data' => ['openTime' => $service->meter($booking, $this->worker($request))]]);
    }

    public function sessionMeter(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $session,
        CleaningOpenTimeLifecycleService $service,
    ): JsonResponse {
        return response()->json(['success' => true, 'data' => [
            'openTime' => $service->meterSession($cleaning_booking, $session, $this->worker($request)),
        ]]);
    }

    public function decideSessionEnd(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $session,
        CleaningOpenTimeLifecycleService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $resolved = $service->decideSessionEnd(
            $cleaning_booking,
            $session,
            $this->worker($request),
            $validated['decision'],
            $validated['reason'] ?? null,
        );

        return response()->json(['success' => true, 'data' => [
            'openTime' => $service->meterSession($cleaning_booking, $resolved, $this->worker($request)),
        ]]);
    }

    private function worker(Request $request): Worker
    {
        $worker = $request->user()?->worker;
        abort_unless($worker instanceof Worker, 403, 'User must have an associated worker.');

        return $worker;
    }
}
