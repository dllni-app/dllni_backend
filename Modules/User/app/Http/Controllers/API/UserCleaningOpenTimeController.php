<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningOpenTimeLifecycleService;

final class UserCleaningOpenTimeController
{
    public function meter(Request $request, int $order, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $booking = $this->owned($request, $order);

        return response()->json(['success' => true, 'data' => ['openTime' => $service->meter($booking, $request->user())]]);
    }

    public function extend(Request $request, int $order, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $validated = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240']]);
        $extension = $service->requestExtension(
            $this->owned($request, $order),
            $request->user(),
            (int) $validated['minutes'],
            $request->header('Idempotency-Key'),
        );

        return response()->json(['success' => true, 'data' => [
            'extension' => $extension,
            'openTime' => $service->meter($extension->booking()->firstOrFail(), $request->user()),
        ]], 201);
    }

    public function end(Request $request, int $order, CleaningOpenTimeLifecycleService $service): JsonResponse
    {
        $booking = $service->requestEnd($this->owned($request, $order), $request->user());

        return response()->json(['success' => true, 'data' => ['openTime' => $service->meter($booking, $request->user())]]);
    }

    public function sessionMeter(
        Request $request,
        int $order,
        CleaningBookingSession $session,
        CleaningOpenTimeLifecycleService $service,
    ): JsonResponse {
        $booking = $this->owned($request, $order);

        return response()->json(['success' => true, 'data' => [
            'openTime' => $service->meterSession($booking, $session, $request->user()),
        ]]);
    }

    public function extendSession(
        Request $request,
        int $order,
        CleaningBookingSession $session,
        CleaningOpenTimeLifecycleService $service,
    ): JsonResponse {
        $validated = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240']]);
        $booking = $this->owned($request, $order);
        $extension = $service->requestSessionExtension(
            $booking,
            $session,
            $request->user(),
            (int) $validated['minutes'],
            $request->header('Idempotency-Key'),
        );

        return response()->json(['success' => true, 'data' => [
            'extension' => $extension,
            'openTime' => $service->meterSession($booking, $session, $request->user()),
        ]], 201);
    }

    public function endSession(
        Request $request,
        int $order,
        CleaningBookingSession $session,
        CleaningOpenTimeLifecycleService $service,
    ): JsonResponse {
        $booking = $this->owned($request, $order);
        $resolved = $service->requestSessionEnd($booking, $session, $request->user());

        return response()->json(['success' => true, 'data' => [
            'openTime' => $service->meterSession($booking, $resolved, $request->user()),
        ]]);
    }

    private function owned(Request $request, int $order): CleaningBooking
    {
        return CleaningBooking::query()->where('customer_id', (int) $request->user()->id)->findOrFail($order);
    }
}
