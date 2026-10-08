<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;

final class CleaningGeographicDispatchService
{
    public const MAX_RADIUS_KM = 50;

    public function hasOpenWorkerSlots(CleaningBooking $booking): bool
    {
        if (! $booking->isTeamFulfilled()) {
            return true;
        }

        // Multi-session events may need more workers for later days
        // even when the parent booking has already received an assignment.
        if ($booking->sessions()->count() <= 1) {
            return false;
        }

        return $booking->sessions()
            ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())
            ->get()
            ->contains(static fn ($session): bool => $session->remainingWorkerCount() > 0);
    }

    public function radiusKm(CleaningBooking $booking): int
    {
        $minutes = max(0, (int) $booking->created_at->diffInMinutes(now()));
        return min(self::MAX_RADIUS_KM, 10 * (1 + intdiv($minutes, 20)));
    }

    public function workerDistanceKm(Worker $worker, CleaningBooking $booking): ?float
    {
        if ($booking->address_latitude === null || $booking->address_longitude === null) {
            return null;
        }

        $position = DB::table('worker_latest_locations')
            ->where('worker_id', $worker->id)
            ->where('recorded_at', '>', now()->subHour())
            ->first(['latitude', 'longitude']);
        if ($position === null) {
            $lastAssignment = DB::table('cleaning_booking_worker_assignments')
                ->where('worker_id', $worker->id)
                ->whereNotNull('last_latitude')
                ->whereNotNull('last_longitude')
                ->where('location_updated_at', '>', now()->subHour())
                ->orderByDesc('location_updated_at')
                ->first(['last_latitude', 'last_longitude']);
            if ($lastAssignment !== null) {
                $position = (object) [
                    'latitude' => $lastAssignment->last_latitude,
                    'longitude' => $lastAssignment->last_longitude,
                ];
            }
        }

        $latitude = $position?->latitude ?? $worker->home_latitude;
        $longitude = $position?->longitude ?? $worker->home_longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $lat1 = deg2rad((float) $booking->address_latitude);
        $lat2 = deg2rad((float) $latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLong = deg2rad((float) $longitude - (float) $booking->address_longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLong / 2) ** 2;

        return 6371.0088 * 2 * asin(min(1.0, sqrt(max(0.0, $a))));
    }

    public function isWithinRadius(Worker $worker, CleaningBooking $booking): bool
    {
        $distance = $this->workerDistanceKm($worker, $booking);

        return $distance !== null && $distance <= $this->radiusKm($booking);
    }
}
