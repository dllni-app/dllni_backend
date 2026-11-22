<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3ClockTransitionException;
use Modules\Cleaning\Services\CleaningV3WorkClockCommandService;

final class CleaningV3WorkClockCommandController
{
    public function requestPause(
        Request $request,
        int $booking,
        int $session,
        CleaningV3WorkClockCommandService $commands,
    ): JsonResponse {
        return $this->dispatch($request, $booking, $session, 'request', $commands);
    }

    public function withdrawPause(
        Request $request,
        int $booking,
        int $session,
        int $pauseRequest,
        CleaningV3WorkClockCommandService $commands,
    ): JsonResponse {
        return $this->dispatch($request, $booking, $session, 'withdraw', $commands, $pauseRequest);
    }

    public function decidePause(
        Request $request,
        int $booking,
        int $session,
        int $pauseRequest,
        CleaningV3WorkClockCommandService $commands,
    ): JsonResponse {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        return $this->dispatch(
            $request, $booking, $session, $validated['decision'], $commands, $pauseRequest,
        );
    }

    public function resume(
        Request $request,
        int $booking,
        int $session,
        CleaningV3WorkClockCommandService $commands,
    ): JsonResponse {
        return $this->dispatch($request, $booking, $session, 'resume', $commands);
    }

    private function dispatch(
        Request $request,
        int $booking,
        int $session,
        string $action,
        CleaningV3WorkClockCommandService $commands,
        ?int $pauseRequestId = null,
    ): JsonResponse {
        $data = $request->validate([
            'commandKey' => ['required', 'string', 'min:1', 'max:96', 'regex:/^[a-zA-Z0-9_.:-]+$/D'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:4294967294'],
        ]);

        try {
            $result = $commands->execute(
                $booking,
                $session,
                $request->user(),
                $action,
                $data['commandKey'],
                (int) $data['expectedRevision'],
                $pauseRequestId,
            );
        } catch (CleaningV3ClockTransitionException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->reasonCode,
                'message' => 'تعذر تنفيذ العملية على عداد الزيارة. حدّث تفاصيل الزيارة وأعد المحاولة.',
            ], $exception->httpStatus, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json([
            'success' => true,
            'data' => $result['clock'],
            'pauseRequestId' => $result['pauseRequestId'],
            'replayed' => $result['replayed'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
