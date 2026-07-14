<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;

final class CleaningBookingWorkerAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'status',
        'accepted_at',
        'started_travel_at',
        'arrived_at',
        'last_latitude',
        'last_longitude',
        'location_updated_at',
        'start_approved_at',
        'work_started_at',
        'work_finished_at',
        'worker_completion_message',
        'worker_finished_cleaning_services',
        'worker_finished_property_rooms',
        'room_count',
        'rooms_weight',
        'service_share_amount',
        'travel_fee',
        'admin_margin_amount',
        'worker_amount',
        'currency',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'status' => CleaningBookingWorkerAssignmentStatus::class,
            'accepted_at' => 'datetime',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'location_updated_at' => 'datetime',
            'start_approved_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'worker_finished_cleaning_services' => 'array',
            'worker_finished_property_rooms' => 'array',
            'room_count' => 'integer',
            'rooms_weight' => 'decimal:2',
            'service_share_amount' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'admin_margin_amount' => 'decimal:2',
            'worker_amount' => 'decimal:2',
        ];
    }

    protected function workerFinishedCleaningServices(): Attribute
    {
        return Attribute::make(
            get: $this->snapshotFallbackGetter('worker_finished_cleaning_services'),
        );
    }

    protected function workerFinishedPropertyRooms(): Attribute
    {
        return Attribute::make(
            get: $this->snapshotFallbackGetter('worker_finished_property_rooms'),
        );
    }

    private function snapshotFallbackGetter(string $bookingColumn): Closure
    {
        return function (mixed $value) use ($bookingColumn): array {
            $snapshot = $this->normalizeSnapshotValue($value);

            if ($snapshot !== []) {
                return $snapshot;
            }

            $booking = $this->relationLoaded('booking')
                ? $this->booking
                : $this->booking()->first();

            if (! $booking instanceof CleaningBooking || max(1, (int) ($booking->number_of_workers ?? 1)) > 1) {
                return $snapshot;
            }

            return $this->normalizeSnapshotValue($booking->getAttribute($bookingColumn));
        };
    }

    /** @return array<int, mixed> */
    private function normalizeSnapshotValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || mb_trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
