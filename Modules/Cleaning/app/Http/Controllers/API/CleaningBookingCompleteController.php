<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Requests\CleaningBookingCompleteRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Services\CleaningBookingService;
use Throwable;

final class CleaningBookingCompleteController
{
    public function __construct(private readonly CleaningBookingService $cleaningBookingService) {}

    /** @throws Throwable */
    public function __invoke(CleaningBookingCompleteRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking);

        try {
            $booking = $this->cleaningBookingService->complete(
                $cleaning_booking,
                $request->completionMessage(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        $booking = $this->loadBookingDetails($booking);
        $finishedServices = $request->finishedCleaningServices();
        $finishedRooms = $request->finishedPropertyRooms();

        $booking->forceFill([
            'worker_finished_cleaning_services' => $finishedServices !== [] ? $finishedServices : $this->inferFinishedServices($booking),
            'worker_finished_property_rooms' => $finishedRooms !== [] ? $finishedRooms : $this->inferFinishedRooms($booking),
        ])->save();

        return CleaningBookingResource::make($this->loadBookingDetails($booking));
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

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

    private function loadBookingDetails(CleaningBooking $booking): CleaningBooking
    {
        return $booking->load([
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
    }

    /** @return array<int, array<string, mixed>> */
    private function inferFinishedServices(CleaningBooking $booking): array
    {
        $services = [];

        if (is_array($booking->cleaning_services)) {
            foreach ($booking->cleaning_services as $index => $service) {
                $name = is_string($service) ? trim($service) : '';
                if ($name !== '') {
                    $services[] = ['id' => null, 'name' => $name, 'label' => $name, 'sort' => $index];
                }
            }
        }

        foreach ($booking->addons as $addon) {
            $name = trim((string) ($addon->name ?? $addon->title ?? ''));
            if ($name !== '') {
                $services[] = ['id' => $addon->id, 'name' => $name, 'label' => $name];
            }
        }

        return array_values($services);
    }

    /** @return array<int, array<string, mixed>> */
    private function inferFinishedRooms(CleaningBooking $booking): array
    {
        return $booking->rooms
            ->map(static fn (CleaningBookingRoom $room): array => [
                'id' => $room->id,
                'roomKey' => $room->room_key,
                'roomType' => $room->room_type,
                'displayLabel' => $room->display_label,
            ])
            ->values()
            ->all();
    }
}
