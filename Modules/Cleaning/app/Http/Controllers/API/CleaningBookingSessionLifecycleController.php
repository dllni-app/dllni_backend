<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Http\Requests\CleaningBookingSosRequest;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionAttendanceService;
use Modules\Cleaning\Services\CleaningBookingSessionCancellationService;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\Cleaning\Services\CleaningBookingSessionSosService;
use Modules\Cleaning\Services\CleaningBookingWorkerScheduleService;

final class CleaningBookingSessionLifecycleController
{
    public function __construct(
        private readonly CleaningBookingSessionLifecycleService $lifecycle,
        private readonly CleaningBookingSessionAttendanceService $attendance,
        private readonly CleaningBookingSessionCancellationService $cancellation,
        private readonly CleaningBookingSessionSosService $sos,
        private readonly CleaningBookingSchedulePresenter $presenter,
        private readonly CleaningBookingWorkerScheduleService $workerSchedules,
    ) {}

    public function startTravel(
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        return $this->workerTransition(
            $cleaning_booking,
            $cleaning_booking_session,
            fn (Worker $worker) => $this->lifecycle->startTravel(
                $cleaning_booking,
                $cleaning_booking_session,
                $worker,
            ),
        );
    }

    public function arrive(
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        return $this->workerTransition(
            $cleaning_booking,
            $cleaning_booking_session,
            fn (Worker $worker) => $this->lifecycle->arrive(
                $cleaning_booking,
                $cleaning_booking_session,
                $worker,
            ),
        );
    }

    public function securityCode(
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $worker = $this->currentWorker();

        try {
            $code = $this->lifecycle->issueSecurityCode(
                $cleaning_booking,
                $cleaning_booking_session,
                $worker,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return response()->json([
            'success' => true,
            'data' => $code,
        ]);
    }

    public function confirmStartVerification(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:4'],
        ]);

        try {
            $session = $this->lifecycle->confirmSecurityCode(
                $cleaning_booking,
                $cleaning_booking_session,
                (int) Auth::id(),
                (string) $validated['code'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($cleaning_booking, $session);
    }

    public function startWork(
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        return $this->workerTransition(
            $cleaning_booking,
            $cleaning_booking_session,
            fn (Worker $worker) => $this->lifecycle->startWork(
                $cleaning_booking,
                $cleaning_booking_session,
                $worker,
            ),
        );
    }

    public function complete(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->workerTransition(
            $cleaning_booking,
            $cleaning_booking_session,
            fn (Worker $worker) => $this->lifecycle->requestCompletion(
                $cleaning_booking,
                $cleaning_booking_session,
                $worker,
                isset($validated['message']) ? (string) $validated['message'] : null,
            ),
        );
    }

    public function confirmCompletion(
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        try {
            $session = $this->lifecycle->confirmCompletion(
                $cleaning_booking,
                $cleaning_booking_session,
                (int) Auth::id(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($cleaning_booking, $session);
    }

    public function cancel(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        $worker = $user?->worker;
        $isCustomer = $user !== null && (int) $cleaning_booking->customer_id === (int) $user->id;

        try {
            if ($isCustomer) {
                $session = $this->cancellation->cancelByCustomer(
                    $cleaning_booking,
                    $cleaning_booking_session,
                    (int) $user->id,
                    (string) $validated['reason'],
                );
                $worker = null;
            } elseif ($worker instanceof Worker) {
                $session = $this->cancellation->cancelByWorker(
                    $cleaning_booking,
                    $cleaning_booking_session,
                    $worker,
                    (string) $validated['reason'],
                );
            } else {
                abort(403, 'You are not allowed to cancel this session.');
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload(
            $cleaning_booking,
            $session,
            $worker instanceof Worker ? $worker : null,
        );
    }

    public function skip(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $user = $request->user();

        if ($user === null || (int) $cleaning_booking->customer_id !== (int) $user->id) {
            abort(403, 'Only the booking customer can skip a recurring session.');
        }

        try {
            $session = $this->cancellation->skipRecurringByCustomer(
                $cleaning_booking,
                $cleaning_booking_session,
                (int) $user->id,
                (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($cleaning_booking, $session);
    }

    public function attendance(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'workerIds' => ['required', 'array', 'min:1'],
            'workerIds.*' => ['required', 'integer', 'distinct', 'exists:workers,id'],
            'action' => ['required', 'string', 'in:wait,replace,cancel'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        if ($user === null || (int) $cleaning_booking->customer_id !== (int) $user->id) {
            abort(403, 'Only the booking customer can use attendance options.');
        }

        try {
            $session = $this->attendance->handle(
                booking: $cleaning_booking,
                session: $cleaning_booking_session,
                customerId: (int) $user->id,
                workerIds: array_map('intval', $validated['workerIds']),
                action: (string) $validated['action'],
                note: isset($validated['note']) ? (string) $validated['note'] : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($cleaning_booking, $session);
    }

    public function sos(
        CleaningBookingSosRequest $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        try {
            $alert = $this->sos->trigger(
                $cleaning_booking,
                $cleaning_booking_session,
                $request->user(),
                $request->validated(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        $worker = $request->user()?->worker;
        $freshBooking = $cleaning_booking->fresh();
        $schedule = $worker instanceof Worker
            ? $this->workerSchedules->present($freshBooking, $worker)
            : $this->presenter->present($freshBooking);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $freshBooking->id,
                'bookingId' => (int) $freshBooking->id,
                'bookingNumber' => $freshBooking->booking_number,
                'status' => $freshBooking->status?->value ?? (string) $freshBooking->status,
                'schedule' => $schedule,
                'sos' => [
                    'id' => (int) $alert->id,
                    'status' => $alert->status?->value ?? (string) $alert->status,
                    'source' => $alert->source,
                    'emergencyType' => $alert->emergency_type?->value ?? (string) $alert->emergency_type,
                    'triggeredAt' => $alert->triggered_at?->toIso8601String(),
                ],
            ],
            'sessionId' => (int) $cleaning_booking_session->id,
        ], 201);
    }

    /** @param callable(Worker): CleaningBookingSession $transition */
    private function workerTransition(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        callable $transition,
    ): JsonResponse {
        $worker = $this->currentWorker();

        try {
            $updated = $transition($worker);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($booking, $updated, $worker);
    }

    private function payload(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        ?Worker $viewerWorker = null,
    ): JsonResponse {
        $freshBooking = $booking->fresh();
        $schedule = $viewerWorker instanceof Worker
            ? $this->workerSchedules->present($freshBooking, $viewerWorker, $session)
            : $this->presenter->present($freshBooking);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $freshBooking->id,
                'bookingId' => (int) $freshBooking->id,
                'bookingNumber' => $freshBooking->booking_number,
                'status' => $freshBooking->status?->value ?? (string) $freshBooking->status,
                'totalPrice' => (float) $freshBooking->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $schedule,
            ],
            'sessionId' => (int) $session->id,
        ]);
    }

    private function currentWorker(): Worker
    {
        $worker = Auth::user()?->worker;
        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        return $worker;
    }
}
