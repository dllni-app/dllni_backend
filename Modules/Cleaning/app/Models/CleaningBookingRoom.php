<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Enums\CleaningBookingRoomAssignmentSource;

final class CleaningBookingRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_booking_id',
        'room_key',
        'room_type',
        'room_size',
        'display_label',
        'weight',
        'assigned_worker_id',
        'assignment_source',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'assigned_worker_id');
    }

    public function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'assignment_source' => CleaningBookingRoomAssignmentSource::class,
        ];
    }
}
