<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\User\Http\Requests\UserCleaningOrderCompletionExtendTimeRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderCompletionExtendTimeController
{
    public function __invoke(
        UserCleaningOrderCompletionExtendTimeRequest $request,
        int $order,
        CleaningBookingWorkerCompletionService $service,
        CleaningBookingSessionLifecycleService $sessionLifecycle,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $targetSession = $this->resolveTargetSession(
            $model,
            $request->targetSessionId(),
        );

        $result = $targetSession instanceof CleaningBookingSession
            ? $sessionLifecycle->requestExtension(
                booking: $model,
                session: $targetSession,
                customerId: (int) $request->user()->id,
                additionalMinutes: (int) $request->validated('additionalMinutes'),
                customerMessage: $request->customerMessage(),
                workerId: $request->targetWorkerId(),
            )
            : $service->requestExtension(
                booking: $model,
                additionalMinutes: (int) $request->validated('additionalMinutes'),
                customerMessage: $request->customerMessage(),
                workerId: $request->targetWorkerId(),
                assignmentId: $request->targetAssignmentId(),
            );

        $updated = $result['booking'];
        $updated->load([
            'worker.user',
            'workerAssignments.worker.user',
            'sessions.workerAssignments.worker.user',
            'rooms.assignedWorker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return UserCleaningBookingResource::make($updated)->additional([
            'message' => __('Extension request sent successfully.'),
            'extensionPricing' => $result['extensionPricing'],
        ])->response();
    }

    private function resolveTargetSession(
        CleaningBooking $booking,
        ?int $requestedSessionId,
    ): ?CleaningBookingSession {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value);

        if ($requestedSessionId !== null) {
            $session = (clone $sessions)->find($requestedSessionId);

            if (! $session instanceof CleaningBookingSession) {
                throw ValidationException::withMessages([
                    'sessionId' => ['The selected cleaning session does not belong to this order.'],
                ]);
            }

            return $session;
        }

        if (! (clone $sessions)->exists()) {
            return null;
        }

        $pendingSessions = (clone $sessions)
            ->where('status', CleaningBookingSessionStatus::AwaitingCustomerCompletion->value)
            ->orderBy('sequence')
            ->get();

        if ($pendingSessions->count() > 1) {
            throw ValidationException::withMessages([
                'sessionId' => ['More than one session is waiting for a time-extension decision. Select the target session.'],
            ]);
        }

        return $pendingSessions->first();
    }
}
