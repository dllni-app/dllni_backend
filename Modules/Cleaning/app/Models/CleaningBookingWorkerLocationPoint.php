<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingWorkerLocationPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_booking_id',
        'cleaning_booking_worker_assignment_id',
        'worker_id',
        'latitude',
        'longitude',
        'recorded_at',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(
            CleaningBookingWorkerAssignment::class,
            'cleaning_booking_worker_assignment_id',
        );
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
