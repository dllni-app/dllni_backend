<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningWorkerLocationHistory extends Model
{
    protected $table = 'cleaning_worker_location_history';

    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'assignment_id',
        'latitude',
        'longitude',
        'recorded_at',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingWorkerAssignment::class, 'assignment_id');
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
