<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingService;
use Modules\Cleaning\Services\CleaningPricingCalculator;

final class CleaningBookingDeliveryFeeController
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly CleaningBookingService $bookingService,
    ) {}

    public function __invoke(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $this->ensureWorkerCanViewBooking($cleaning_booking, $worker);

        if ($cleaning_booking->address_latitude === null || $cleaning_booking->address_longitude === null) {
            throw ValidationException::withMessages([
                'bookingLocation' => ['Order location coordinates are required to calculate delivery fee.'],
            ]);
        }

        $payload = $request->validate([
            'workerLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'workerLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'worker_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'worker_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $originLatitude = $payload['workerLatitude'] ?? $payload['worker_latitude'] ?? $worker->home_latitude;
        $originLongitude = $payload['workerLongitude'] ?? $payload['worker_longitude'] ?? $worker->home_longitude;

        if ($originLatitude === null || $originLongitude === null) {
            throw ValidationException::withMessages([
                'workerLocation' => ['Worker location coordinates are required to calculate delivery fee.'],
            ]);
        }

        try {
            $pricing = $this->pricingCalculator->finalizedForCoordinates(
                (float) ($cleaning_booking->base_price ?? 0),
                (float) ($cleaning_booking->addons_total ?? 0),
                (float) $cleaning_booking->address_latitude,
                (float) $cleaning_booking->address_longitude,
                (float) $originLatitude,
                (float) $originLongitude,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'deliveryFee' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery fee calculated successfully.',
            'data' => [
                'bookingId' => $cleaning_booking->id,
                'booking_id' => $cleaning_booking->id,
                'distanceKm' => $pricing['distanceKm'],
                'distance_km' => $pricing['distanceKm'],
                'deliveryFee' => $pricing['travelFee'],
                'delivery_fee' => $pricing['travelFee'],
                'travelFee' => $pricing['travelFee'],
                'travel_fee' => $pricing['travelFee'],
                'adminMargin' => $pricing['adminMargin'],
                'admin_margin' => $pricing['adminMargin'],
                'totalPrice' => $pricing['totalPrice'],
                'total_price' => $pricing['totalPrice'],
                'currency' => (string) config('app.currency', 'SYP'),
            ],
        ]);
    }

    public function finish(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $request->user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $this->ensureWorkerCanActOnBooking($cleaning_booking, $worker);

        $payload = $request->validate([
            'finish_type' => ['required', 'string', 'in:success,dispute'],
            'dispute_reason_type' => ['required_if:finish_type,dispute', 'nullable', 'string', 'max:100'],
            'dispute_reason_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $booking = $this->bookingService->finish(
                $cleaning_booking,
                (string) $payload['finish_type'],
                isset($payload['dispute_reason_type']) ? (string) $payload['dispute_reason_type'] : null,
                isset($payload['dispute_reason_note']) ? (string) $payload['dispute_reason_note'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        $booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);

        $status = $booking->status instanceof CleaningBookingStatus ? $booking->status->value : (string) $booking->status;
        $data = CleaningBookingResource::make($booking)->resolve($request);
        $latestDispute = $booking->disputes->sortByDesc('id')->first();

        $data['isTimerRunning'] = false;
        $data['timerStoppedAt'] = $booking->work_finished_at?->toIso8601String();
        $data['suspendedMessage'] = $status === CleaningBookingStatus::UnderDispute->value
            ? 'تم تعليق الطلب وتحويله للإدارة للتحقق والفصل يدوياً'
            : null;
        $data['dispute'] = $latestDispute ? [
            'id' => $latestDispute->id,
            'status' => $latestDispute->status?->value ?? $latestDispute->status,
            'statusLabel' => $latestDispute->status?->label() ?? null,
            'reasonType' => $latestDispute->category?->value ?? $latestDispute->category,
            'reasonLabel' => $latestDispute->category?->label() ?? null,
            'reasonNote' => $latestDispute->description,
            'ticketNumber' => $latestDispute->ticket_number,
            'openedAt' => $latestDispute->created_at?->toIso8601String(),
        ] : null;

        return response()->json([
            'success' => true,
            'message' => $status === CleaningBookingStatus::Completed->value
                ? 'تم إنهاء المهمة بنجاح'
                : 'تم تعليق الطلب وتحويله للإدارة للتحقق والفصل يدوياً',
            'data' => $data,
        ]);
    }

    private function ensureWorkerCanViewBooking(CleaningBooking $booking, Worker $worker): void
    {
        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking, Worker $worker): void
    {
        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($booking->worker_id === null && ! $hasWorkerAssignment) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }
}
