<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPricingCalculator;

final class CleaningBookingDeliveryFeeController
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
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
}
