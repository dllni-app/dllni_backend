<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\CleaningFinancialSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderSessionController
{
    public function __construct(
        private readonly CleaningBookingSessionLifecycleService $lifecycle,
    ) {}

    public function confirmStart(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:4'],
        ]);

        return $this->respond(fn () => $this->lifecycle->confirmSecurityCode(
            $booking,
            $session,
            $request->user(),
            (string) $validated['code'],
        ), __('Security code verified successfully.'));
    }

    public function confirmCompletion(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        return $this->respond(fn () => $this->lifecycle->confirmCompletion($booking, $session, $request->user()), __('Completion confirmed successfully.'));
    }

    public function rejectCompletion(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(fn () => $this->lifecycle->rejectCompletion(
            $booking,
            $session,
            $request->user(),
            $validated['message'] ?? $validated['reason'] ?? null,
        ), __('Completion rejected successfully.'));
    }

    public function extendTime(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        $validated = $request->validate([
            'additionalMinutes' => ['required', 'integer', 'min:0', 'max:90'],
            'message' => ['nullable', 'string', 'max:1000'],
            'workerId' => ['nullable', 'integer', 'exists:workers,id'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
        ]);

        try {
            $result = $this->lifecycle->requestExtension(
                $booking,
                $session,
                $request->user(),
                (int) $validated['additionalMinutes'],
                $validated['message'] ?? null,
                isset($validated['workerId']) ? (int) $validated['workerId'] : (isset($validated['worker_id']) ? (int) $validated['worker_id'] : null),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        $updated = $result['booking']->load([
            'worker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return UserCleaningBookingResource::make($updated)->additional([
            'message' => __('Extension request sent successfully.'),
            'extensionPricing' => $result['extensionPricing'],
            'warningId' => $result['warning']->id,
            'sessionId' => $session->id,
        ])->response();
    }

    public function cancel(Request $request, CleaningBooking $booking, CleaningBookingSession $session): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $fee = CleaningFinancialSetting::currentUserCancellationFee();

        return $this->respond(fn () => $this->lifecycle->cancelSession(
            $booking,
            $session,
            'customer',
            $validated['reason'] ?? null,
            $fee,
        ), __('Session cancelled successfully.'));
    }

    /** @param callable():CleaningBooking $callback */
    private function respond(callable $callback, string $message): JsonResponse
    {
        try {
            $booking = $callback();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        $booking->load([
            'worker.user',
            'preferredWorker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return UserCleaningBookingResource::make($booking)->additional(['message' => $message])->response();
    }
}
