<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Illuminate\Support\Collection;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class WorkerAssignmentHoursResolver
{
    /**
     * Resolve the hours a worker should spend on a booking based on assigned room weight share.
     *
     * @param  Collection<int, CleaningBookingRoom>|iterable<int, CleaningBookingRoom>|null  $rooms
     */
    public static function resolve(
        CleaningBooking $booking,
        ?CleaningBookingWorkerAssignment $assignment = null,
        iterable|null $rooms = null,
    ): float {
        $bookingHours = self::bookingHours($booking);

        if ($bookingHours <= 0) {
            return 0.0;
        }

        if (max(1, (int) ($booking->number_of_workers ?? 1)) <= 1) {
            return $bookingHours;
        }

        if (! $assignment instanceof CleaningBookingWorkerAssignment) {
            return $bookingHours;
        }

        $roomsCollection = self::roomsCollection($booking, $rooms);
        $totalWeight = self::totalRoomsWeight($booking, $roomsCollection);
        $workerWeight = self::workerRoomsWeight($assignment, $roomsCollection);

        if ($totalWeight <= 0 || $workerWeight <= 0) {
            return $bookingHours;
        }

        return self::roundToHalfHour($bookingHours * ($workerWeight / $totalWeight));
    }

    public static function bookingHours(CleaningBooking $booking): float
    {
        $totalHours = (float) ($booking->total_hours ?? 0);

        if ($totalHours > 0) {
            return $totalHours;
        }

        return max(0.0, (float) ($booking->estimated_hours ?? 0));
    }

    public static function roundToHalfHour(float $hours): float
    {
        return ceil($hours * 2.0) / 2.0;
    }

    /**
     * @param  Collection<int, CleaningBookingRoom>|iterable<int, CleaningBookingRoom>|null  $rooms
     * @return Collection<int, CleaningBookingRoom>
     */
    private static function roomsCollection(CleaningBooking $booking, iterable|null $rooms): Collection
    {
        if ($rooms instanceof Collection) {
            return $rooms;
        }

        if (is_iterable($rooms)) {
            return Collection::make($rooms);
        }

        if ($booking->relationLoaded('rooms')) {
            return $booking->rooms;
        }

        return CleaningBookingRoom::query()
            ->where('cleaning_booking_id', $booking->id)
            ->get();
    }

    /**
     * @param  Collection<int, CleaningBookingRoom>  $rooms
     */
    private static function totalRoomsWeight(CleaningBooking $booking, Collection $rooms): float
    {
        if ($rooms->isNotEmpty()) {
            return round((float) $rooms->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);
        }

        if ($booking->relationLoaded('workerAssignments')) {
            return round((float) $booking->workerAssignments->sum(
                fn (CleaningBookingWorkerAssignment $assignment): float => (float) ($assignment->rooms_weight ?? 0)
            ), 2);
        }

        return 0.0;
    }

    /**
     * @param  Collection<int, CleaningBookingRoom>  $rooms
     */
    private static function workerRoomsWeight(CleaningBookingWorkerAssignment $assignment, Collection $rooms): float
    {
        $storedWeight = round((float) ($assignment->rooms_weight ?? 0), 2);

        if ($storedWeight > 0) {
            return $storedWeight;
        }

        if ($rooms->isEmpty()) {
            return 0.0;
        }

        return round((float) $rooms
            ->filter(fn (CleaningBookingRoom $room): bool => (int) ($room->assigned_worker_id ?? 0) === (int) $assignment->worker_id)
            ->sum(fn (CleaningBookingRoom $room): float => (float) $room->weight), 2);
    }
}
